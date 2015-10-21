<?php

/**
 * @file
 * Contains \Drupal\mongodb\MongodbLock.
 */

namespace Drupal\mongodb;

use Drupal\Core\Lock\LockBackendAbstract;

class MongodbLock extends LockBackendAbstract {

  /**
   * The mongodb factory registered as a service.
   *
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $mongo;

  /**
   * Construct the DatabaseFileUsageBackend.
   *
   * @param \Drupal\mongodb\MongoCollectionFactory $mongo
   *   The mongodb collection factory.
   */
  public function __construct(MongoCollectionFactory $mongo) {
    // __destruct() is causing problems with garbage collections, register a
    // shutdown function instead.
    drupal_register_shutdown_function(array($this, 'releaseAll'));
    $this->mongo = $mongo;
  }

  /**
   * Acquires a lock.
   *
   * @param string $name
   *   Lock name. Limit of name's length is 255 characters.
   * @param float $timeout = 30.0
   *   (optional) Lock lifetime in seconds.
   *
   * @return bool
   */
  public function acquire($name, $timeout = 30.0) {
    // Insure that the timeout is at least 1 ms.
    $timeout = max($timeout, 0.001);
    $expire = microtime(TRUE) + $timeout;
    if (isset($this->locks[$name])) {
      // Try to extend the expiration of a lock we already acquired.
      $result = $this->mongoCollection()->update(['_id' => $this->mongoId($name)], ['expire' => $expire]);
      $success = !empty($result['n']);
      if (!$success) {
        // The lock was broken.
        unset($this->locks[$name]);
      }
      return $success;
    }
    else {
      // Optimistically try to acquire the lock, then retry once if it fails.
      // The first time through the loop cannot be a retry.
      $retry = FALSE;
      // We always want to do this code at least once.
      do {
        try {
          $this->mongoCollection()->insert([
            '_id' => $this->mongoId($name),
            'expire' => $expire,
          ]);
          // We track all acquired locks in the global variable.
          $this->locks[$name] = TRUE;
          // We never need to try again.
          $retry = FALSE;
        }
        catch (\MongoDuplicateKeyException $e) {
          // Suppress the error. If this is our first pass through the loop,
          // then $retry is FALSE. In this case, the insert failed because some
          // other request acquired the lock but did not release it. We decide
          // whether to retry by checking lockMayBeAvailable(). This will clear
          // the offending row from the database table in case it has expired.
          $retry = $retry ? FALSE : $this->lockMayBeAvailable($name);
        }
        // We only retry in case the first attempt failed, but we then broke
        // an expired lock.
      } while ($retry);
    }
    return isset($this->locks[$name]);
  }

  /**
   * @param $name
   * @return array
   */
  protected function mongoId($name) {
    return [
      'name' => $name,
      'value' => $this->getLockId(),
    ];
  }

  /**
   * Checks if a lock is available for acquiring.
   *
   * @param string $name
   *   Lock to acquire.
   *
   * @return bool
   */
  public function lockMayBeAvailable($name) {
    $lock = $this->mongoCollection()->findOne(['_id.name' => $name]);
    if (!$lock) {
      return TRUE;
    }
    $expire = (float) $lock['expire'];
    $now = microtime(TRUE);
    if ($now > $expire) {
      // We check two conditions to prevent a race condition where another
      // request acquired the lock and set a new expire time. We add a small
      // number to $expire to avoid errors with float to string conversion.
      return (bool) $this->mongoCollection()->remove([
        '_id.name' => $name,
        'value' => $lock['value'],
        'expire' => ['$lte' => 0.0001 + $expire],
      ]);
    }
    return FALSE;
  }

  /**
   * Releases the given lock.
   *
   * @param string $name
   */
  public function release($name) {
    unset($this->locks[$name]);
    $this->mongoCollection()->remove(['_id' => $this->mongoId($name)]);
  }

  /**
   * Releases all locks for the given lock token identifier.
   *
   * @param string $lockId
   *   (optional) If none given, remove all locks from the current page.
   *   Defaults to NULL.
   */
  public function releaseAll($lockId = NULL) {
    // Only attempt to release locks if any were acquired.
    if (!empty($this->locks)) {
      $this->locks = array();
      if (empty($lock_id)) {
        $lock_id = $this->getLockId();
      }
      $this->mongoCollection()->remove(['_id.value' => $lock_id]);
    }
  }

  /**
   * @return \MongoCollection
   */
  protected function mongoCollection() {
    return $this->mongo->get('semaphore');
  }
}
