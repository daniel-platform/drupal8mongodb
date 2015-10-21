<?php

/**
 * @file
 * QueueMongodb functionality.
 */

namespace Drupal\mongodb;

use Drupal\Driver\Database\mongodb\Connection;

/**
 * MongoDB batch queue implementation.
 */
class BatchQueueMongodb extends QueueMongodb {

  /**
   * The object wrapping the MongoDB database object.
   *
   * @var MongoCollectionFactory
   */
  protected $mongo;

  /**
   * MongoDB collection name.
   *
   * @var string
   */
  protected $collection;

  /**
   * Construct this object.
   *
   * @param string $name
   *   Name of the queue.
   */
  public function __construct($name) {
    $this->collection = 'queue.' . $name;
  }

  /**
   * Claim an item in the queue for processing.
   *
   * @param string $lease_time
   *   How long the processing is expected to take in seconds,
   *
   * @return object/boolean
   *   On success we return an item object. If the queue is unable to claim
   *   an item it returns false.
   */
  public function claimItem($lease_time = 30) {
    $this->garbageCollection();
    $result = $this->mongoCollection()
      ->find(['expire' => 0])
      ->limit(1)
      ->sort(['created' => 1]);
    if ($result->hasNext()) {
      $return = (object) $result->getNext();
      $return->data = unserialize($return->data);
      return $return;
    }
    return FALSE;
  }


  /**
   * Retrieves all remaining items in the queue.
   *
   * This is specific to Batch API and is not part of the
   * \Drupal\Core\Queue\QueueInterface
   *
   * @return array
   *   An array of queue items.
   */
  public function getAllItems() {
    $result = [];
    $items = $this->mongoCollection()->find()->sort(['_id' => 1]);

    foreach ($items as $item) {
      $result[] = unserialize($item['data']);
    }
    return $result;
  }

  /**
   @return \MongoCollection
   */
  protected function mongoCollection() {
    if (!isset($this->mongo)) {
      $this->mongo = \Drupal::service('mongo');
    }
    return $this->mongo->get($this->collection);
  }

}
