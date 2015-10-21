<?php
use Drupal\mongodb\MongoCollectionFactory;
use Drupal\mongodb\MongodbConfigStorageBootstrap;

$class_loader->addPsr4('Drupal\mongodb\\', "$app_root/modules/mongodb/src");
$settings['bootstrap_config_storage'] = function () use ($class_loader, $settings, &$config)  {
  if (class_exists('Drupal\mongodb\MongodbConfigStorageBootstrap')) {
    $config['core.extension']['module']['mongodb_comment'] = 0;
    $config['core.extension']['module']['mongodb_dblog'] = 0;
    $config['core.extension']['module']['mongodb_file'] = 0;
    $config['core.extension']['module']['mongodb_user'] = 0;
    $config['core.extension']['module']['mongodb_node'] = 0;
    $config['core.extension']['module']['mongodb_taxonomy'] = 0;
    $config['core.extension']['module']['mongodb'] = 0;
    if (isset($settings['mongo'])) {
      $mongo = new MongoCollectionFactory($settings['mongo']);
    }
    else {
      $mongo = MongoCollectionFactory::createFromDatabase();
    }
    return new MongodbConfigStorageBootstrap($mongo, $class_loader);
  }
};
$settings['cache']['default'] = 'cache.backend.mongodb';
$settings['queue_default'] = 'queue.mongodb';
$GLOBALS['conf']['container_service_providers'][] = 'Drupal\mongodb\MongoDbServiceOverrideProvider';
$config['system.logging']['error_level'] = 'verbose';
