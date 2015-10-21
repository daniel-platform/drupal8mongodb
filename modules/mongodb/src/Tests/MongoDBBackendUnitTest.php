<?php

/**
 * @file
 * Definition of Drupal\system\Tests\Cache\DatabaseBackendUnitTest.
 */

namespace Drupal\mongodb\Tests;

use Drupal\mongodb\CacheBackendMongodb;
use Drupal\system\Tests\Cache\GenericCacheBackendUnitTestBase;

/**
 * Tests DatabaseBackend using GenericCacheBackendUnitTestBase.
 *
 * @group Cache
 */
class MongoDBBackendUnitTest extends GenericCacheBackendUnitTestBase {
  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('mongodb');

  public static function getInfo() {
    return array(
      'name' => 'MongoDB cache backend',
      'description' => 'Unit test of the MongoDB backend using the generic cache unit test base.',
      'group' => 'MongoDB',
    );
  }

  /**
   * Creates a new instance of DatabaseBackend.
   *
   * @return
   *   A new DatabaseBackend object.
   */
  protected function createCacheBackend($bin) {
    return \Drupal::service('cache.backend.mongodb')->get($bin);
  }

}
