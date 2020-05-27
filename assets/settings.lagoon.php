<?php

/**
 * @file
 * Lagoon Drupal 8 configuration file.
 *
 * You should not edit this file, please use environment-specific files!
 * They are loaded in this order:
 * - all.settings.php
 *   For settings that should be applied to all environments.
 * - all.services.yml
 *   For services that should be applied to all environments.
 * - production.settings.php
 *   For settings only for the production environment.
 * - production.services.yml
 *   For services only for the production environment.
 * - development.settings.php
 *   For settings only for all non production environments.
 * - development.services.yml
 *   For services only for all non production environments.
 * - settings.local.php
 *   For settings only for the local environment.
 * - services.local.yml
 *   For services only for the local environment.
 */

// Lagoon version.
if (!defined("LAGOON_VERSION")) {
  define("LAGOON_VERSION", "1");
}

// Lagoon database connection.
if (getenv('LAGOON')) {
  $databases['default']['default'] = [
    'driver' => 'mysql',
    'database' => getenv('MARIADB_DATABASE') ?: 'drupal',
    'username' => getenv('MARIADB_USERNAME') ?: 'drupal',
    'password' => getenv('MARIADB_PASSWORD') ?: 'drupal',
    'host' => getenv('MARIADB_HOST') ?: 'mariadb',
    'port' => 3306,
    'prefix' => '',
  ];
}

// Lagoon reverse proxy settings.
if (getenv('LAGOON')) {
  $settings['reverse_proxy'] = TRUE;
}

// Trusted Host Patterns.
// see https://www.drupal.org/node/2410395 for more information.
// If your site runs on multiple domains, you need to add these domains here.
// escape dots, remove schema, use commas as regex separator.
if (getenv('LAGOON_ROUTES')) {
  $patterns = str_replace(['.', 'https://', 'http://', ','], ['\.', '', '', '|'], getenv('LAGOON_ROUTES'));
  $settings['trusted_host_patterns'] = ['^' . $patterns . '$'];
}

// Temp directory.
if (getenv('TMP')) {
  $config['system.file']['path']['temporary'] = getenv('TMP');
}

// Hash salt.
if (getenv('LAGOON')) {
  $settings['hash_salt'] = hash('sha256', getenv('LAGOON_PROJECT'));
}

// The default list of directories that will be ignored by Drupal's file API.
if (empty($settings['file_scan_ignore_directories'])) {
  $settings['file_scan_ignore_directories'] = [
    'node_modules',
    'bower_components',
  ];
}

// Settings for all environments.
if (file_exists(__DIR__ . '/all.settings.php')) {
  include __DIR__ . '/all.settings.php';
}

// Services for all environments.
if (file_exists(__DIR__ . '/all.services.yml')) {
  $settings['container_yamls'][] = __DIR__ . '/all.services.yml';
}

if (getenv('LAGOON_ENVIRONMENT_TYPE')) {
  // Environment specific settings files.
  if (file_exists(__DIR__ . '/' . getenv('LAGOON_ENVIRONMENT_TYPE') . '.settings.php')) {
    include __DIR__ . '/' . getenv('LAGOON_ENVIRONMENT_TYPE') . '.settings.php';
  }

  // Environment specific services files.
  if (file_exists(__DIR__ . '/' . getenv('LAGOON_ENVIRONMENT_TYPE') . '.services.yml')) {
    $settings['container_yamls'][] = __DIR__ . '/' . getenv('LAGOON_ENVIRONMENT_TYPE') . '.services.yml';
  }
}
