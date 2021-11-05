<?php

// Redis configuration.
if (getenv('LAGOON')) {
    $redis = new \Redis();
    $redis_host = getenv('REDIS_HOST') ?: 'redis';
    $redis_port = getenv('REDIS_SERVICE_PORT') ?: 6379;
    try {
        # Do not use the cache during installations of Drupal.
        if (InstallerKernel::installationAttempted()) {
        throw new \Exception('Drupal installation underway.');
        }
    
        # Use a timeout to ensure that if the Redis pod is down, that Drupal will
        # continue to function.
        if ($redis->connect($redis_host, $redis_port, 1) === FALSE) {
        throw new \Exception('Redis server unreachable.');
        }
    
        $response = $redis->ping();
        if (strpos($response, 'PONG') === FALSE) {
        throw new \Exception('Redis could be reached but is not responding correctly.');
        }
    
        $settings['redis.connection']['interface'] = 'PhpRedis';
        $settings['redis.connection']['host'] = $redis_host;
        $settings['redis.connection']['port'] = $redis_port;
        $settings['cache_prefix']['default'] = getenv('REDIS_CACHE_PREFIX') ?: getenv('LAGOON_PROJECT') . '_' . getenv('LAGOON_GIT_SAFE_BRANCH');
    
        $settings['cache']['default'] = 'cache.backend.redis';
    
        // Include the default example.services.yml from the module, which will
        // replace all supported backend services (that currently includes the cache tags
        // checksum service and the lock backends, check the file for the current list).
        $settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';
    
        // Allow the services to work before the Redis module itself is enabled.
        $settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';
    
        // Manually add the classloader path, this is required for the container cache bin definition below
        // and allows to use it without the redis module being enabled.
        $class_loader->addPsr4('Drupal\\redis\\', 'modules/contrib/redis/src');
    
        // Use redis for container cache.
        // The container cache is used to load the container definition itself, and
        // thus any configuration stored in the container itself is not available
        // yet. These lines force the container cache to use Redis rather than the
        // default SQL cache.
        $settings['bootstrap_container_definition'] = [
        'parameters' => [],
        'services' => [
            'redis.factory' => [
            'class' => 'Drupal\redis\ClientFactory',
            ],
            'cache.backend.redis' => [
            'class' => 'Drupal\redis\Cache\CacheBackendFactory',
            'arguments' => ['@redis.factory', '@cache_tags_provider.container', '@serialization.phpserialize'],
            ],
            'cache.container' => [
            'class' => '\Drupal\redis\Cache\PhpRedis',
            'factory' => ['@cache.backend.redis', 'get'],
            'arguments' => ['container'],
            ],
            'cache_tags_provider.container' => [
            'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
            'arguments' => ['@redis.factory'],
            ],
            'serialization.phpserialize' => [
            'class' => 'Drupal\Component\Serialization\PhpSerialize',
            ],
        ],
        ];
        }
        catch (\Exception $error) {
        $settings['container_yamls'][] = 'sites/default/redis-unavailable.services.yml';
        $settings['cache']['default'] = 'cache.backend.null';
        }
    }