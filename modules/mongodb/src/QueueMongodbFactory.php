<?php

/**
 * @file
 * Contains \Drupal\mongodb\QueueMongodbFactory.
 */

namespace Drupal\mongodb;

/**
 * Defines the queue factory for the MongoDB backend.
 */
class QueueMongodbFactory {

  /**
   * Mongo collection factory.
   *
   * @var \Drupal\mongodb\MongoCollectionFactory $mongo
   */
  protected $mongo;

  /**
   * Constructs this factory object.
   *
   * @param \Drupal\mongodb\MongoCollectionFactory $mongo
   *   Mongo collection factory.
   */
  function __construct(MongoCollectionFactory $mongo) {
    $this->mongo = $mongo;
  }

  /**
   * Constructs a new queue object for a given name.
   *
   * @param string $name
   *   The name of the collection holding key and value pairs.
   *
   * @return \Drupal\mongodb\QueueMongodb
   *   A queue implementation for the given queue.
   */
  public function get($name) {
    return new QueueMongodb($this->mongo, $name);
  }
}
