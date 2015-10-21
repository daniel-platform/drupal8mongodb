<?php

/**
 * @file
 * Contains \Drupal\mongodb\QueueMongodb
 */

namespace Drupal\mongodb;

use Drupal\Core\Queue\ReliableQueueInterface;

/**
 * MongoDB queue implementation.
 */
class QueueMongodb implements ReliableQueueInterface {

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
   * @param MongoCollectionFactory $mongo
   *   MongoDB collection factory.
   *
   * @param string $collection
   *   Name of the queue.
   */
  public function __construct(MongoCollectionFactory $mongo, $name) {
    $this->mongo = $mongo;
    $this->collection = 'queue.' . $name;
  }

  /**
   * Add a queue item and store it directly to the queue.
   *
   * @param object $data
   *   Arbitrary data to be associated with the new task in the queue
   *
   * @return boolean
   *   If the item was successfully created and added to the queue.
   */
  public function createItem($data) {
    $item = array(
      'data' => serialize($data),
      'created' => time(),
      'expire' => 0,
    );
    return $this->mongoCollection()->insert($item);
  }

  /**
   * Retrieve the number of items in the queue.
   *
   * @return integer
   *   An integer estimate of the number of items in the queue.
   */
  public function numberOfItems() {
    return $this->mongoCollection()->count();
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
    $result = $this->mongoCollection()->findAndModify(
      array('expire' => 0),
      array('$set' => array('expire' => time() + $lease_time)),
      array(),
      array('sort' => array('created' => 1))
    );
    if (!$result) {
      return FALSE;
    }
    $result['data'] = unserialize($result['data']);

    return empty($result) ? FALSE : (object) $result;
  }

  /**
   * Automatically release items, that have been claimed and exceeded lease time.
   */
  protected function garbageCollection() {
    $this->mongoCollection()->update(
      array('expire' => array('$gt' => 0, '$lt' => REQUEST_TIME)),
      array('$set' => array('expire' => 0)),
      array('multiple' => TRUE)
    );
  }

  /**
   * Release an item that the worker could not process
   *
   * @param object $item
   *   The item to release.
   */
  public function releaseItem($item) {
    return $this->mongo
      ->get($this->collection)
      ->update(
        array('_id' => $item->_id),
        array('$set' => array('expire' => 0))
      );
  }

  /**
   * Delete a finished item from the queue.
   *
   * @param object $item
   *   The item to delete.
   */
  public function deleteItem($item) {
    $this->mongoCollection()->remove(array('_id' => $item->_id));
  }

  /**
   * Create a queue.
   */
  public function createQueue() {
    $this->mongoCollection()->ensureIndex(array('expire' => 1, 'created' => 1));
  }

  /**
   * Delete a queue and every item in the queue.
   */
  public function deleteQueue() {
    $this->mongoCollection()->drop();
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
