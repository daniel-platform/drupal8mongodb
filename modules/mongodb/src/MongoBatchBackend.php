<?php

/**
 * @file
 * Contains \Drupal\mongodb\MongoBatchBackend.
 */

namespace Drupal\mongodb;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Batch\BatchStorageInterface;
use Drupal\Core\Session\SessionManagerInterface;

class MongoBatchBackend implements BatchStorageInterface {

  /**
   * The mongodb factory registered as a service.
   *
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $mongo;

  /**
   * @var \Drupal\Core\Session\SessionManagerInterface
   */
  protected $sessionManager;

  /**
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * Construct the DatabaseFileUsageBackend.
   *
   * @param \Drupal\mongodb\MongoCollectionFactory $mongo
   *   The mongodb collection factory.
   */
  public function __construct(MongoCollectionFactory $mongo, SessionManagerInterface $session_manager, CsrfTokenGenerator $csrf_token) {
    $this->mongo = $mongo;
    $this->sessionManager = $session_manager;
    $this->csrfToken = $csrf_token;
  }

  /**
   * Loads a batch.
   *
   * @param int $id
   *   The ID of the batch to load.
   *
   * @return array
   *   An array representing the batch, or FALSE if no batch was found.
   */
  public function load($id) {
    // Ensure that a session is started before using the CSRF token generator.
    $this->sessionManager->start();
    $batch = $this->mongoCollection()->findOne(['_id' => (int) $id, 'token' => $this->csrfToken->get($id)]);
    return $batch ? unserialize($batch['batch']) : $batch;
  }

  /**
   * Creates and saves a batch.
   *
   * @param array $batch
   *   The array representing the batch to create.
   */
  public function create(array $batch) {
    // Ensure that a session is started before using the CSRF token generator.
    $this->sessionManager->start();
    $this->mongoCollection()->insert([
      '_id' => (int) $batch['id'],
      'timestamp' => REQUEST_TIME,
      'token' => $this->csrfToken->get($batch['id']),
      'batch' => serialize($batch),
    ]);
  }

  /**
   * Updates a batch.
   *
   * @param array $batch
   *   The array representing the batch to update.
   */
  public function update(array $batch) {
    $new['$set']['batch'] = serialize($batch);
    $this->mongoCollection()->update(['_id' => (int) $batch['id']], $new);
  }

  /**
   * Loads a batch.
   *
   * @param int $id
   *   The ID of the batch to delete.
   */
  public function delete($id) {
    $this->mongoCollection()->remove(['_id' => (int) $id]);
  }

  /**
   * Cleans up failed or old batches.
   */
  public function cleanup() {
    // Cleanup the batch table and the queue for failed batches.
    $remove['expire']['$lt'] = REQUEST_TIME - 864000;
    $this->mongoCollection()->remove($remove);
  }

  /**
   * @return \MongoCollection
   */
  protected function mongoCollection() {
    return $this->mongo->get('batch');
  }
}
