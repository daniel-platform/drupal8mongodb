<?php

namespace Drupal\Driver\Database\mongodb\Install;

use Drupal\Component\Utility\OpCodeCache;
use Drupal\Core\Database\Install\Tasks as BaseTasks;
use Drupal\Core\DependencyInjection\YamlFileLoader;
use Drupal\Core\Site\Settings;

class Tasks extends BaseTasks {

  /**
   * Structure that describes each task to run.
   *
   * @var array
   *
   * Each value of the tasks array is an associative array defining the function
   * to call (optional) and any arguments to be passed to the function.
   */
  protected $tasks = array(
    array(
      'function'    => 'checkEngineVersion',
      'arguments'   => array(),
    ),
    array(
      'function'    => 'installSettings',
      'arguments'   => array(),
    ),
  );

  /**
   * Return the human-readable name of the driver.
   */
  public function name() {
    return t('MongoDB');
  }

  public function runTasks() {
    if (!Settings::get('bootstrap_config_storage')) {
      \Drupal::service('class_loader')->addPsr4('Drupal\mongodb\\', 'modules/mongodb/src');
    }
    parent::runTasks();
  }

  protected function hasPdoDriver() {
    throw new \LogicException('I am not supposed to be called.');
  }

  public function installable() {
    return extension_loaded('mongo') && file_exists(DRUPAL_ROOT . '/modules/mongodb/src/MongoCollectionFactory.php');
  }

  public function getFormOptions(array $database) {
    $form = parent::getFormOptions($database);

    // Remove the options that only apply to client/server style databases.
    unset($form['username'], $form['password'], $form['advanced_options']['host'], $form['advanced_options']['port']);

    // Make the text more accurate for MongoDB.
    $form['database']['#title'] = t('Connection string');
    $form['database']['#description'] = t('The connection string to the MongoDB install/cluster where @drupal data will be stored.', array('@drupal' => drupal_install_profile_distribution_name()));
    $default_database = 'mongodb://localhost:27017';
    $form['database']['#default_value'] = empty($database['database']) ? $default_database : $database['database'];
    return $form;
  }

  protected function installSettings() {
    if (Settings::get('bootstrap_config_storage')) {
      return;
    }

    $conf_path = conf_path(FALSE);
    copy(__DIR__ .'/settings.php', "$conf_path/settings.testing.php");
    $settingsfile = "$conf_path/settings.php";
    file_put_contents($settingsfile, "include __DIR__ . '/settings.testing.php';\n", FILE_APPEND);
    // Now re-read settings.php.
    /** @var \Drupal\Core\Installer\InstallerKernel $kernel */
    $kernel = \Drupal::service('kernel');
    $class_loader = \Drupal::service('class_loader');
    $site_path = $kernel->getSitePath();
    for ($dir = __DIR__; $dir && !is_dir("$dir/core"); $dir = dirname($dir));
    // Invalidate settings.php before loading it.
    OpCodeCache::invalidate($settingsfile);
    // Now reload settings.php.
    Settings::initialize($dir, $site_path, $class_loader);
    // And make the next rebuild utilize the new bootstrap config storage.
    $kernel->resetConfigStorage();
  }

}
