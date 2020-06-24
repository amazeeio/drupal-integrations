<?php
namespace Drush\Commands\drupal_integrations;

use Drush\Commands\DrushCommands;
use Drush\Drupal\ExtensionDiscovery;
use Drush\Drush;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush integration for Lagoon.
 */
class lagoonCommands extends DrushCommands
{

    private $api;
    private $cache_timeout;
    private $endpoint;
    private $lagoon_file_name;
    private $jwttoken;
    private $project_name;
    private $ssh_host;
    private $ssh_port;
    private $ssh_port_timeout;

    function __construct()
    {
        $this->api = getenv('LAGOON_OVERRIDE_API') ?: 'https://api.lagoon.amazeeio.cloud/graphql';
        $this->cache_timeout = 600;
        $this->endpoint = getenv('LAGOON_OVERRIDE_SSH') ?: 'ssh.lagoon.amazeeio.cloud:32222';
        $this->lagoon_file_name = '.lagoon.yml';
        $this->jwttoken = getenv('LAGOON_OVERRIDE_JWT_TOKEN');
        $this->project_name = getenv('LAGOON_PROJECT');
        $this->ssh_port_timeout = getenv('LAGOON_OVERRIDE_SSH_TIMEOUT') ?: 30;
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
        // For CI: allow to completely disable lagoon alias loading
        if (getenv('LAGOON_DISABLE_ALIASES')) {
            $this->logger()->notice('LAGOON_DISABLE_ALIASES is set, bailing out of loading lagoon aliases');
            return;
        }

        $this->getLagoonAliases();
    }

    /**
     * Retrieves the contents of the sites .lagoon.yml file.
     */
    public function getLagoonYml()
    {
        // Find a .lagoon.yml file.
        $this->logger()->debug('Finding Project Root');
        $project_root = Drush::bootstrapManager()->getComposerRoot();

        $this->logger()->debug("Looking for $this->lagoon_file_name file to extract project name within '$project_root'");
        $lagoonyml_path = $project_root . "/" . $this->lagoon_file_name;

        if (file_exists($lagoonyml_path)) {
            $this->logger()->debug("Using .lagoon.yml file at: '$lagoonyml_path'");
            return Yaml::parse( file_get_contents($lagoonyml_path) );
        } else {
            $this->logger()->warning('Could not find .lagoon.yml file.');
            return FALSE;
        }
    }

    /**
     * Converts the endpoint into SSH host and port.
     */
    public function getSshHostPort()
    {
        if (count(explode(":", $this->endpoint)) == 2) {
            list ($this->ssh_host, $this->ssh_port) = explode(":", $this->endpoint);
        } else {
            throw new \Exception(dt('Error: Wrong formatted ssh endpoint - !ssh, it should be in form "[host]:[port]"', ['!ssh' => $this->endpoint]));
        }
    }

    /**
     * Tests SSH connection.
     */
    public function checkSshConnection()
    {
        $ssh_port_check = @fsockopen($this->ssh_host, $this->ssh_port, $errno, $errstr, $this->ssh_port_timeout);
        if (is_resource($ssh_port_check))
        {
            fclose($ssh_port_check);
        } else {
            throw new \Exception(dt('Error: Could not connect to !ssh_host port !ssh_port, error was !errno:!errstr.', 
            ['!ssh_host' => $this->ssh, 'ssh_port' => $this->ssh_port, '!errno' => $errno, '!errstr' => $errstr]));
        }

        $this->logger()->debug("Connection successful to $this->endpoint");
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

        $this->checkSshConnection();

        $cmd = "timeout $this->ssh_port_timeout ssh -p $this->ssh_port -o ConnectTimeout=5 -o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no lagoon@$this->ssh_host token 2>&1";
        $this->logger()->debug("Retrieving token via SSH - $cmd");
        exec($cmd, $token_array, $rc);
        if ($rc !== 0) {
            throw new \Exception(dt('Could not load API JWT Token, error was: !error', ['!error' => implode(",", $token_array)]));
        }

        $this->logger->debug("JWT Token loaded via ssh: " . $token_array[0]);
        drush_cache_set($cid, $token_array[0], 'default', $this->cache_timeout);
        return $token_array[0];
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

        $this->logger()->debug("Loading environments for '$project_name' from the API.");

        $query = sprintf('{
            project:projectByName(name: "%s") {
                productionEnvironment
                standbyProductionEnvironment
                productionAlias
                standbyAlias
                environments {
                name
                openshiftProjectName
                }
            }
            }
            ', $this->project_name);
    
        $this->logger->debug("Using $this->api as lagoon API endpoint");

        $curl = curl_init($this->api);

        // Build up the curl options for the GraphQL query. When using the content type
        // 'application/json', graphql-express expects the query to be in the json
        // encoded post body beneath the 'query' property.
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_POSTREDIR, CURL_REDIR_POST_ALL);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "Authorization: Bearer $this->jwt_token"]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array(
        'query' => $query,
        )));

        $this->logger->debug("Sending to api: " . $query);
        $response = curl_exec($curl);
        $this->logger->debug("Response from api: " . var_export($response, true));

        // Check if the curl request succeeded.
        if ($response === FALSE) {
            throw new \Exception(dt('API request failed, error was: !error', ['!error' => curl_error($curl)]));
        }

        curl_close($curl);
        drush_cache_set($cid, $response, 'default', $this->cache_timeout);
        return json_decode($response);
    }

    /**
     * Gets a list of remote drush aliases.
     */
    public function getLagoonAliases() 
    {
        if ($lagoonyml = $this->getLagoonYml()) {

            if (empty($this->project_name) && isset($lagoonyml['project'])) {
                $this->project_name = $lagoonyml['project'];
            }

            if (empty($this->api) && isset($lagoonyml['api'])) {
                $this->api = $lagoonyml['api'];
            }            

            if (empty($this->endpoint) && isset($lagoonyml['ssh'])) {
                $this->endpoint = $lagoonyml['ssh'];
            }

            // sometimes ssh port may not reachable, so we should be able to fail sooner
            if (empty($this->ssh_port_timeout) && isset($lagoonyml['ssh_port_timeout'])) {
                $this->ssh_port_timeout = $lagoonyml['ssh_port_timeout'];
            }
        }

        // Project still not defined, throw a warning.
        if ($this->project_name === FALSE) {
            $this->logger->warning('ERROR: Could not discover project name, you should define it inside your .lagoon.yml file', 'warning');
            return;
        }

        $this->logger()->debug("

            Using the following configuration to load aliases:

            Project Name: $this->project_name
            API Endpoint: $this->api
            SSH Endpoint: $this->endpoint
            SSH Timeout: $this->ssh_port_timeout

        ");

        $this->getSshHostPort();
        $this->jwt_token = $this->getJwtToken();
        
        $response = $this->getLagoonEnvs();
        $this->logger()->debug("Decoded response from api: " . var_export($response, true));

        // Check if the query returned any data for the requested project.
        if (empty($response->data->project->environments)) {
            $this->logger()->warning("API request didn't return any environments for the given project '$this->project_name'.");
            return;
        }

        foreach ($response->data->project->environments as $env) {
            $this->io()->writeln('@lagoon.' . $env->name);
        }

    }
}
