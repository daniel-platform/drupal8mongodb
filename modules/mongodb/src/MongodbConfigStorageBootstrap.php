<?php

/**
 * @file
 * Definition of Drupal\mongodb\Config\ConfigStorageBootstrap.
 */

namespace Drupal\mongodb;

use Composer\Autoload\ClassLoader;
use Drupal\Core\Database\Database;
use Drupal\Core\Extension\Extension;

class MongodbConfigStorageBootstrap extends MongodbConfigStorage {

  /**
   * TRUE if the storage was already read.
   *
   * The test runner causes the system to not read the module list, this
   * variable keeps track of that.
   *
   * @var bool
   */
  protected $read;

  /**
   * @var \Composer\Autoload\ClassLoader
   */
  protected $classLoader;

  public function __construct(MongoCollectionFactory $mongo, ClassLoader $class_loader) {
    parent::__construct($mongo);
    $this->classLoader = $class_loader;
  }

  /**
   * {@inheritdoc}
   */
  public function read($name) {
    if (!$this->read && $name != 'core.extension' && isset($GLOBALS['config']['core.extension']['module']['mongodb'])) {
      $debug = debug_backtrace();
      do {
        $current = array_pop($debug);
        if (isset($current['class']) && $current['class'] == 'Drupal\Core\Test\TestRunnerKernel') {
          /** @var \Drupal\Core\Test\TestRunnerKernel $kernel */
          $kernel = $current['object'];
          $mongodb_path = substr(dirname(__DIR__), strlen($kernel->getAppRoot()) + 1);

          $r = new \ReflectionObject($kernel);

          $service_providers = $r->getProperty('serviceProviders');
          $service_providers->setAccessible(TRUE);
          $value = $service_providers->getValue($kernel);
          $value['app'][] = new MongodbServiceProvider;
          $service_providers->setValue($kernel, $value);

          $services = $r->getProperty('serviceYamls');
          $services->setAccessible(TRUE);
          $value = $services->getValue($kernel);
          $services_file = $mongodb_path . '/mongodb.services.yml';
          if (empty($value['app']) || !in_array($services_file, $value['app'])) {
            $value['app'][] = $services_file;
            foreach (array_keys($GLOBALS['config']['core.extension']['module']) as $module_name) {
              if (substr($module_name, 0, 8) == 'mongodb_') {
                $module_path = "$mongodb_path/$module_name";
                $filename = "$module_path/$module_name.services.yml";
                if (file_exists($filename)) {
                  $value['app'][] = $filename;
                  $this->classLoader->addPsr4('Drupal\\' . $module_name . '\\', DRUPAL_ROOT . "/$module_path/src");
                }
              }
            }
            $services->setValue($kernel, $value);
          }
          break;
        }
      } while ($debug);
    }
    $this->read = TRUE;
    $result = parent::read($name);
    if ($name == 'core.extension' && isset($GLOBALS['config'][$name]['module'])) {
      if (!$result) {
        $result = array('module' => array());
      }
      $read_module = $result['module'];
      $result['module'] += $GLOBALS['config'][$name]['module'];
      if (count($read_module) != count($result['module'])) {
        parent::write($name, $result);
      }
    }
    return $result;
  }

}
