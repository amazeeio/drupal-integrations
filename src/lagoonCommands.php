<?php
namespace Drush\Commands\drupal_integrations;

use Drush\Commands\DrushCommands;
use Drush\Drupal\ExtensionDiscovery;
use Drush\Drush;
use GuzzleHttp\Client;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush integration for Lagoon.
 */
class lagoonCommands extends DrushCommands
{

    private $api;
    private $cache_timeout;
    private $endpoint;
    private $jwttoken;
    private $project_name;
    private $ssh_host;
    private $ssh_port;
    private $ssh_port_timeout;

    function __construct()
    {
        // Get default config.
        $lagoonyml = $this->getLagoonYml();
        $this->api = isset($lagoonyml['api']) ? $lagoonyml['api'] : 'https://api.lagoon.amazeeio.cloud/graphql';
        $this->cache_timeout = 600;
        $this->endpoint = isset($lagoonyml['ssh']) ? $lagoonyml['ssh'] : 'ssh.lagoon.amazeeio.cloud:32222';
        $this->jwt_token = getenv('LAGOON_OVERRIDE_JWT_TOKEN');
        $this->project_name = isset($lagoonyml['project']) ? $lagoonyml['project'] : '';
        $this->ssh_port_timeout = isset($lagoonyml['ssh_port_timeout']) ? $lagoonyml['ssh_port_timeout'] : 30;

        // Allow environment variable overrides.
        $this->api = getenv('LAGOON_OVERRIDE_API') ?: $this->api;
        $this->endpoint = getenv('LAGOON_OVERRIDE_SSH') ?: $this->endpoint;
        $this->project_name = getenv('LAGOON_PROJECT') ?: $this->project_name;
        $this->ssh_port_timeout = getenv('LAGOON_OVERRIDE_SSH_TIMEOUT') ?: $this->ssh_port_timeout;
    }

    /**
     * @command lagoon:aliases
     *
     * @aliases la
     *
     * Get all remote aliases from lagoon API.
     */
    public function aliases()
    {
        // Project still not defined, throw a warning.
        if ($this->project_name === FALSE) {
            $this->logger()->warning('ERROR: Could not discover project name, you should define it inside your .lagoon.yml file');
            return;
        }

        if (empty($this->jwt_token)) {
            $this->jwt_token = $this->getJwtToken();
        }

        $response = $this->getLagoonEnvs();

        // Check if the query returned any data for the requested project.
        if (empty($response->data->project->environments)) {
            $this->logger()->warning("API request didn't return any environments for the given project '$this->project_name'.");
            return;
        }

        foreach ($response->data->project->environments as $env) {
            $this->io()->writeln('@lagoon.' . $env->openshiftProjectName);
        }
    }

    /**
     * Retrieves the contents of the sites .lagoon.yml file.
     */
    public function getLagoonYml()
    {
        $project_root = Drush::bootstrapManager()->getComposerRoot();
        $lagoonyml_path = $project_root . "/.lagoon.yml";
        return (file_exists($lagoonyml_path)) ? Yaml::parse(file_get_contents($lagoonyml_path)) : FALSE;
    }

    /**
     * Retrives a JWT token from the Lagoon SSH endpoint.
     */
    public function getJwtToken() 
    {
        // Try to pull the token from the cache.
        $cid = "lagoon_jwt_token";
        $cache = drush_cache_get($cid);

        if (isset($cache->data) && time() < $cache->expire && getenv('LAGOON_IGNORE_DRUSHCACHE') === FALSE) {
            $this->logger()->debug("Found cached JWT token.");
            return $cache->data;
        }

        list ($this->ssh_host, $this->ssh_port) = explode(":", $this->endpoint);

        $args = "-o ConnectTimeout=5 -o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no";
        $cmd = "ssh -p $this->ssh_port $args lagoon@$this->ssh_host token 2>&1";
        $this->logger()->debug("Retrieving token via SSH - $cmd");
        $ssh = Process::fromShellCommandline($cmd);
        $ssh->setTimeout($this->ssh_port_timeout);

        try {
            $ssh->mustRun();
        } catch (ProcessFailedException $exception) {
            $this->logger()->warning($exception->getMessage());
        }

        if (!$ssh->isSuccessful()) {
            throw new ProcessFailedException($ssh);
        }

        $token = trim($ssh->getOutput());
        $this->logger->debug("JWT Token loaded via ssh: " . $token);
        drush_cache_set($cid, $token, 'default', $this->cache_timeout);
        return $token;
    }

    /**
     * Retrieves all information about environments from the Lagoon API.
     */
    public function getLagoonEnvs() 
    {
        // Try to pull the token from the cache.
        $cid = "lagoon_envs_" . $this->project_name;
        $cache = drush_cache_get($cid);

        if (isset($cache->data) && time() < $cache->expire && getenv('LAGOON_IGNORE_DRUSHCACHE') === FALSE) {
            $this->logger()->debug("Found cached environments.");
            return json_decode($cache->data);
        }

        $this->logger()->debug("Loading environments for '$this->project_name' from the API '$this->api'");
        $query = sprintf('{
                project:projectByName(name: "%s") {
                    productionEnvironment,
                    standbyProductionEnvironment,
                    productionAlias,
                    standbyAlias,
                    environments {
                    name,
                    openshiftProjectName
                    }
                }
            }', $this->project_name);

        $this->logger->debug("Sending to api: " . $query);
        $client = new Client();
        $request = $client->request('POST', $this->api, [
            'base_uri' => $this->api,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->jwt_token,
            ],
            'body' => json_encode(['query' => $query]),
        ]);

        if ($request->getStatusCode() == '200') {
            $response = strval($request->getBody());
        } else {
            throw new \Exception(dt('Error: Could not connect to lagooon API !api', ['!api' => $this->api]));
        }

        $this->logger->debug("Response from api: " . var_export(json_decode($response), true));
        drush_cache_set($cid, $response, 'default', $this->cache_timeout);
        return json_decode($response);
    }
}
