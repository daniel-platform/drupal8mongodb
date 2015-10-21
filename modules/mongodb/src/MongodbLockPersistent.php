<?php

/**
 * @file
 * Contains \Drupal\mongodb\MongodbLockPersistent.
 */

namespace Drupal\mongodb;

class MongodbLockPersistent extends MongodbLock {

  public function __construct(MongoCollectionFactory $mongo) {
    // Do not call the parent constructor to avoid registering a shutdown
    // function that releases all the locks at the end of a request.
    $this->mongo = $mongo;
    // Set the lockId to a fixed string to make the lock ID the same across
    // multiple requests. The lock ID is used as a page token to relate all the
    // locks set during a request to each other.
    // @see \Drupal\Core\Lock\LockBackendInterface::getLockId()
    $this->lockId = 'persistent';
  }

}
