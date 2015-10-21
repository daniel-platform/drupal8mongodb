<?php
/**
 * @file
 * Contains
 */

use Drupal\Core\Config\BootstrapConfigStorageFactory;
use Drupal\Core\PhpStorage\PhpStorageFactory;
use Drupal\mongodb\MongodbConfigStorage;

function mongodb_drush_command() {
  $commands['mongodb_config_takeover'] = array(
    'description' => 'Add mongodb as the default config',
    'aliases' => array('mongo-cto'),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_FULL,
    'arguments' => array(
      'source' => 'Source service name.',
      'target' => 'Target service name.',
    ),
  );
  return $commands;
}

function drush_mongodb_config_takeover() {
  $source = Drupal::service('config.storage');
  $destination = Drupal::service('mongodb.config.storage');
  foreach ($source->listAll() as $name) {
    $destination->write($name, $source->read($name));
  }
  foreach ($source->getAllCollectionNames() as $collection_name) {
    $collection_destination = $destination->createCollection($collection_name);
    $collection_source = $source->createCollection($collection_name);
    foreach ($collection_source->listAll() as $name) {
      $collection_destination->write($name, $collection_source->read($name));
    }
  }
  // Copied from drush.
  $alias_record = drush_sitealias_get_record('@self');
  $sites_subdir = drush_sitealias_local_site_path($alias_record);
  // Override with sites-subdir if specified.
  if ($dir = drush_get_option('sites-subdir')) {
    $sites_subdir = "sites/$dir";
  }
  $conf_path = $sites_subdir;
  $settingsfile = "$conf_path/settings.php";
  $servicesfile = "$conf_path/services.yml";

   if (is_writable($settingsfile) && is_writeable($servicesfile)) {
     if (!BootstrapConfigStorageFactory::get() instanceof MongodbConfigStorage) {
       $config_factory = sprintf(<<<'EOD'
// Appended by MongoDB config takeover
$settings['bootstrap_config_storage'] = function ($class_loader = NULL) use ($settings)  {
  if ($class_loader) {
    $class_loader->addPsr4('Drupal\mongodb\\', '%s/src');
  }
  if (class_exists('Drupal\mongodb\Config\MongoDbStorageBootstrap')) {
    return new Drupal\mongodb\Config\MongoDbStorageBootstrap(new Drupal\mongodb\MongoCollectionFactory($settings));
  }
};
EOD
         , drupal_get_path('module', 'mongodb'));
       file_put_contents($settingsfile, $config_factory, FILE_APPEND);
     }

     $services = file_get_contents($servicesfile);
     if (!preg_match('/^services:/', $services)) {
       $services .= "services:\n";
     }
     $services .= '  config.storage: "@mongodb.config.storage"' . "\n";
     file_put_contents($servicesfile, $services);

     PhpStorageFactory::get('service_container')->deleteAll();
   }
}
