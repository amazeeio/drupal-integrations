<?php

namespace Drush\Commands\drupal_integrations;

use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use \Symfony\Component\HttpKernel\Kernel;

/**
 * Drush integration for Lagoon.
 */
class LagoonCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;

  /**
   * Default ssh host, used for fallback
   */
  const DEFAULT_SSH_HOST = 'ssh.lagoon.amazeeio.cloud';

  /**
   * Default ssh port, used for fallback
   */
  const DEFAULT_SSH_PORT = 32222;

  /**
   * Lagoon API endpoint.
   *
   * @var string
   */
  private $api;

  /**
   * Lagoon SSH endpoint.
   *
   * @var string
   */
  private $endpoint;

  /**
   * JWT token.
   *
   * @var string
   */
  private $jwttoken;

  /**
   * Lagoon project name.
   *
   * @var string
   */
  private $projectName;

  /**
   * Connection timeout.
   *
   * @var int
   */
  private $sshTimeout;

  /**
   * Path to a specific SSH key to use for lagoon authentication.
   *
   * @var int
   */
  private $sshKey;

  /**
   * {@inheritdoc}
   */
  public function __construct() {
    // Get default config.
    $lagoonyml = $this->getLagoonYml();
    $this->api = $lagoonyml['api'] ?? 'https://api.lagoon.amazeeio.cloud/graphql';
    $this->endpoint = $lagoonyml['ssh'] ?? sprintf("%s:%s", self::DEFAULT_SSH_HOST, self::DEFAULT_SSH_PORT);
    $this->jwt_token = getenv('LAGOON_OVERRIDE_JWT_TOKEN');
    $this->projectName = $lagoonyml['project'] ?? '';
    $this->ssh_port_timeout = $lagoonyml['ssh_port_timeout'] ?? 30;
    // Allow environment variable overrides.
    $this->api = getenv('LAGOON_OVERRIDE_API') ?: $this->api;
    $this->endpoint = getenv('LAGOON_OVERRIDE_SSH') ?: $this->endpoint;
    $this->projectName = getenv('LAGOON_PROJECT') ?: $this->projectName;
    $this->sshTimeout = getenv('LAGOON_OVERRIDE_SSH_TIMEOUT') ?: $this->sshTimeout;
    $this->sshKey = getenv('LAGOON_SSH_KEY');
  }

  /**
   * Get all remote aliases from lagoon API.
   *
   * @command lagoon:aliases
   *
   * @aliases la
   */
  public function aliases() {
    // Project still not defined, throw a warning.
    if ($this->projectName === FALSE) {
      $this->logger()->warning('ERROR: Could not discover project name, you should define it inside your .lagoon.yml file');
      return;
    }

    if (empty($this->jwt_token)) {
      $this->jwt_token = $this->getJwtToken();
    }

    $response = $this->getLagoonEnvs();
    // Check if the query returned any data for the requested project.
    if (empty($response->data->project->environments)) {
      $this->logger()->warning("API request didn't return any environments for the given project '$this->projectName'.");
      return;
    }

    foreach ($response->data->project->environments as $env) {
      $alias = '@lagoon.' . $env->kubernetesNamespaceName;

      // Add production flag.
      if ($env->name === $response->data->project->productionEnvironment) {
        $alias .= ' <fg=yellow;bg=black>(production)</>';
      }

      $this->io()->writeln($alias);
    }
  }

  /**
   * Get and print remote aliases from lagoon API site aliases file.
   *
   * @param string $file
   *   Optional, output the alias file to a particular file.
   *
   * @command lagoon:generate-aliases
   *
   * @aliases lg
   */
  public function generateAliases($file = NULL) {
    // Project still not defined, throw a warning.
    if ($this->projectName === FALSE) {
      $this->logger()
        ->warning('ERROR: Could not discover project name, you should define it inside your .lagoon.yml file');
      return;
    }

    if (empty($this->jwt_token)) {
      $this->jwt_token = $this->getJwtToken();
    }

    $response = $this->getLagoonEnvs();
    // Check if the query returned any data for the requested project.
    if (empty($response->data->project->environments)) {
      $this->logger()
        ->warning("API request didn't return any environments for the given project '$this->projectName'.");
      return;
    }

    foreach ($response->data->project->environments as $env) {
      $details = [
        "host" => $env->kubernetes->sshHost ?: self::DEFAULT_SSH_HOST,
        "user" => $env->kubernetesNamespaceName,
        "paths" => ["files" => "/app/web/sites/default/files"],
        "ssh" => [
          "options" => sprintf('-o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o LogLevel=FATAL -p %s', $env->kubernetes->sshPort ?: self::DEFAULT_SSH_PORT),
          "tty" => "false",
        ],
      ];

      $alias[$env->name] = $details;
    }

    $aliasContents = "";

    try {
      $aliasContents = Yaml::dump($alias, 2);
    }
    catch (\Exception $exception) {
      $this->logger->warning("Unable to dump alias yaml: " . $exception->getMessage());
    }

    if (!is_null($file)) {
      if (file_put_contents($file, $aliasContents) === FALSE) {
        $this->logger->warning("Unable to write aliases to " . $file);
      }
      else {
        $this->logger->warning("Successfully wrote aliases to " . $file);
      }
    }
    else {
      $this->io()->writeln($aliasContents);
    }
  }

  /**
   * Generate a JWT token for the lagoon API.
   *
   * @command lagoon:jwt
   *
   * @aliases jwt
   */
  public function generateJwt() {
    $this->io()->writeln($this->getJwtToken());
  }

  /**
   * Run pre-rollout tasks.
   *
   * @command lagoon:pre-rollout-tasks
   */
  public function preRolloutTasks() {
    $this->runRolloutTasks('pre');
  }

  /**
   * Run post-rollout tasks.
   *
   * @command lagoon:post-rollout-tasks
   */
  public function postRolloutTasks() {
    $this->runRolloutTasks('post');
  }

  /**
   * Runs rollout tasks on local or remote environments.
   */
  public function runRolloutTasks($stage = 'post') {
    $alias = $this->siteAliasManager()->getSelf();

    // Load tasks from .lagoon.yml.
    $lagoonyml = $this->getLagoonYml();
    if (isset($lagoonyml['tasks'][$stage . '-rollout'])) {
      foreach ($lagoonyml['tasks'][$stage . '-rollout'] as $task) {
        if ($task['run']['service'] == 'cli') {
          $process = $this->processManager()->siteProcess($alias, explode(' ', $task['run']['command']));
          $process->mustRun($process->showRealtime());
        }
        else {
          $this->logger()->warning("Only commands in the 'cli' service can be run via drush.");
        }
      }
    }
    else {
      $this->logger()->warning("No $stage rollout tasks found in .lagoon.yml");
    }
  }

  /**
   * Retrieves the contents of the sites .lagoon.yml file.
   */
  public function getLagoonYml() {
    $project_root = Drush::bootstrapManager()->getComposerRoot();
    $lagoonyml_path = $project_root . "/.lagoon.yml";
    return (file_exists($lagoonyml_path)) ? Yaml::parse(file_get_contents($lagoonyml_path)) : [];
  }

  /**
   * Retrives a JWT token from the Lagoon SSH endpoint.
   */
  public function getJwtToken() {
    [$ssh_host, $ssh_port] = explode(":", $this->endpoint);

    $args = [
      "-p",  $ssh_port,
      "-o", "ConnectTimeout=5",
      "-o", "LogLevel=FATAL",
      "-o", "UserKnownHostsFile=/dev/null",
      "-o", "StrictHostKeyChecking=no",
    ];

    if ($this->sshKey) {
      $args += ["-i",  $this->sshKey];
    }

    $cmd = ["ssh", ...$args, "lagoon@$ssh_host", "token"];

    $this->logger()->debug("Retrieving token via SSH -" . implode(" ", $cmd));
    if (version_compare(Kernel::VERSION, "4.2", "<")) {
      // Symfony >= 4.2 only allows the array form of the command parameter
      $ssh = new Process(implode(" ", $cmd));
    }
    else {
      $ssh = new Process($cmd);
    }

    $ssh->setTimeout($this->sshTimeout);
    
    try {
      $ssh->mustRun();  
    } catch (ProcessFailedException $exception) {
      $this->logger->debug($ssh->getMessage());
    }

    $token = trim($ssh->getOutput());
    $this->logger->debug("JWT Token loaded via ssh: " . $token);
    return $token;
  }

  /**
   * Retrieves all information about environments from the Lagoon API.
   */
  public function getLagoonEnvs() {
    $this->logger()->debug("Loading environments for '$this->projectName' from the API '$this->api'");
    $query = sprintf('{
                project:projectByName(name: "%s") {
                    productionEnvironment,
                    standbyProductionEnvironment,
                    productionAlias,
                    standbyAlias,
                    environments {
                    name,
                    kubernetesNamespaceName,
                    kubernetes {
                      sshHost,
                      sshPort
                      }
                    }
                }
            }', $this->projectName);

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
    }
    else {
      throw new \Exception(dt('Error: Could not connect to lagooon API !api', ['!api' => $this->api]));
    }

    $this->logger->debug("Response from api: " . var_export(json_decode($response), TRUE));
    return json_decode($response);
  }

}
