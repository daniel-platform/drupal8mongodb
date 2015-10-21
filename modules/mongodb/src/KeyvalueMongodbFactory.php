<?php

/**
 * @file
 * Definition of Drupal\mongodb\MongoKeyValueFactory.
 */

namespace Drupal\mongodb;

use \Drupal\Core\Site\Settings;

class KeyvalueMongodbFactory {

  /**
   * @var MongoCollectionFactory $mongo
   */
  protected $mongo;

  /**
   * The settings array.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * The prefix. keyvalue or keyvalue.expirable.
   *
   * @var string
   */
  protected $prefix;

  /**
   * @param MongoCollectionFactory $mongo
   * @param \Drupal\Core\Site\Settings $settings
   * @param string $prefix
   */
  function __construct(MongoCollectionFactory $mongo, Settings $settings, $prefix) {
    $this->mongo = $mongo;
    $this->settings = $settings;
    $this->prefix = $prefix;
  }

  function get($collection) {
    $mongo_collection = "$this->prefix.$collection";

    $settings = $this->settings->get('mongo');
    if (isset($settings['keyvalue']['ttl'])) {
      $ttl = $settings['keyvalue']['ttl'];
    }
    else {
      $ttl = 300;
    }
    $this->mongo->get($mongo_collection)->ensureIndex(array('expire' => 1), array('expireAfterSeconds' => $ttl));
    $this->mongo->get($mongo_collection)->ensureIndex(array('_id' => 1, 'expire' => 1));
    return new KeyvalueMongodb($this->mongo, $collection);
  }

}
