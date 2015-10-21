Note: most of README.txt is not valid. For now, if you want to test this:
- put the module in /modules/mongodb in the Drupal root.
- copy the drivers directory to your Drupal root.

VARIABLES
------------
MongoDB uses the $settings['mongo'] to store connection settings.  EXAMPLE:
  $settings['mongo'] = array(
    'servers' => array(
      // Connection name/alias
      'default' => array(
        // Omit USER:PASS@ if Mongo isn't configured to use authentication.
        'server' => 'mongodb://USER:PASS@localhost',
        // Database name
        'db' => 'drupal_default',
      ),
      // Connection name/alias
      'floodhost' => array(
        'server' => 'mongodb://flood.example.com',
        'db' => 'flood',
      ),
    ),
    'collections' => array(
      'flood' => 'floodhost',
    ),
  );

Using MongoDB for config storage:
----------------------------------------------------------------------
Enable the module and run drush mongo-cto. This will copy the current
configuration from SQL into MongoDB and edit services.yml and
settings.php for you.

Installing config storage into MongoDB:
----------------------------------------------------------------------
If you do not yet have a site then the friendly drush command is not
available and you need to copy-paste the following lines into settings.php:
--- CUT HERE ---
$settings['bootstrap_config_storage'] = function ($class_loader = NULL) use ($settings, &$config)  {
  if ($class_loader) {
    $class_loader->addPsr4('Drupal\mongodb\\', 'modules/mongodb/src');
  }
  if (class_exists('Drupal\mongodb\MongodbConfigStorageBootstrap')) {
    $config['core.extension']['module']['mongodb'] = 0;
    return new Drupal\mongodb\MongodbConfigStorageBootstrap(new Drupal\mongodb\MongoCollectionFactory($settings));
  }
};
--- CUT HERE ---

and the following into sites/default/services.yml:

--- CUT HERE ---
services:
  config.storage: "@mongodb.config.storage"
--- CUT HERE ---


Using MongoDB selectively:
----------------------------------------------------------------------
In the parameters: section of sites/default/services.yml remove
default_backend: mongo and add aliases for service separately.


Cache backend configuration:
----------------------------------------------------------------------

Enable mongodb.module and add this to your settings.php:

  $settings['cache']['default'] = 'cache.backend.mongodb';

This will enable MongoDB cache backend for all cache bins. If you want
to configure backends on per-bin basis just replace 'default' with
desired cache bin ('config', 'block', bootstrap', ...).

We set "expireAfterSeconds" option on {'expire' : 1} index. MongoDB will automatically
purge all temporary cache items TTL seconds after their expiration. Default value
for TTL is 300. This value can be changed by adding this lime to settings.php
(replace 3600 with desired TTL):

  $settings['mongo']['cache']['ttl'] = 3600;


KeyvalueMongodb backend configuration:
-----------------------------------------------------------------------

Works very similar as cache backends. To enable mongo KeyvalueMongodb store for all
keyvalue collections put this in settings.php:

  $settings['keyvalue_default'] = 'mongodb.keyvalue';

For expirable collections:

  $settings['keyvalue_expirable_default'] = 'mongodb.keyvalue';

This will set mongo as default backend. To enable it on per-collection basis use
(replace [collection_name] with a desired keyvalue collection - state, update, module_list, etc.):

  $settings['keyvalue_service_[collection_name]'] = 'mongodb.keyvalue';

or

  $settings['keyvalue_expirable_service_[collection_name]'] = 'mongodb.keyvalue';

We use "TTL" mongo collections for expirable keyvalue service. You can set TTL by
adding this line to settings.php.

  $settings['mongo']['keyvalue']['ttl'] = 3600;

Note that takeover module supports keyvalue as well:

drush takeover mongodb.keyvalue

will copy everything from the keyvalue SQL table to the mongodb collection.

QueueMongodb backend configuration:
-----------------------------------------------------------------------

Works very similar as cache backends. To enable mongo queue store for all
queues put this in settings.php:

  $settings['queue_default'] = 'queue.mongodb';

This will set mongo as default backend. To enable it on per-queue basis use
(replace [queue_name] with a desired queue):

  $settings['queue_service_[queue_name]'] = 'queue.mongodb';

or for reliable queues:

  $settings['queue_reliable_service_[queue_name]'] = 'queue.mongodb';

Watchdog module:
------------------------------------------------------------------------

The CSS in the watchdog module assumes the module to be installed in
modules/mongodb to locate the core report icon files correctly.

Testing with MongoDB:
------------------------------------------------------------------------

A core patch is included, after applying it, the settings.testing.php will be
incldued with every web-test and the relevant MongoDB modules will be enabled.
Ie. when running aggregator tests, mongodb_aggregator.module will be enabled
and it will provide aggregator storage. For non-web based tests, the
$test_settings variable can be used instead of $settings to add new settings.
This is ongoing work.
