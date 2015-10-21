<?php

/**
 * @file
 * Contains \Drupal\Cache\mongodb\CacheBackendMongodbFactory.
 */

namespace Drupal\mongodb;

use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Site\Settings;

/**
 * Factory for CacheBackendMongodb objects.
 *
 * @see \Drupal\Core\Cache\CacheFactory
 */
class CacheBackendMongodbFactory {

  /**
   * The MongoDB database object.
   *
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $mongo;

  /**
   * The settings array.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * @var \Drupal\Core\Cache\CacheTagsChecksumInterface
   */
  protected $checksumProvider;

  /**
   * Constructs the CacheBackendMongodbFactory object.
   *
   * @param \Drupal\mongodb\MongoCollectionFactory $mongo
   */
  function __construct(MongoCollectionFactory $mongo, Settings $settings, CacheTagsChecksumInterface $checksum_provider) {
    $this->mongo = $mongo;
    $this->settings = $settings;
    $this->checksumProvider = $checksum_provider;

  }

  /**
   * Gets CacheBackendMongodb for the specified cache bin.
   *
   * @param $bin
   *   The cache bin for which the object is created.
   *
   * @return \Drupal\mongodb\CacheBackendMongodb
   *   The cache backend object for the specified cache bin.
   */
  function get($bin) {
    if ($bin != 'cache') {
      $bin = 'cache_' . $bin;
    }
    $collection = $this->mongo->get($bin);
    $collection->ensureIndex(array('tags' => 1));
    $settings = $this->settings->get('mongo');
    if (isset($settings['cache']['ttl'])) {
      $ttl = $settings['cache']['ttl'];
    }
    else {
      $ttl = 300;
    }
    $collection->ensureIndex(array('expire' => 1), array('expireAfterSeconds' => $ttl));
    return new CacheBackendMongodb($this->mongo->get($bin), $this->checksumProvider);
  }

}
