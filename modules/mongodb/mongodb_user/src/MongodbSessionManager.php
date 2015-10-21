<?php

/**
 * @file
 * Contains \Drupal\mongodb_user\MongodbSessionManager.
 */

namespace Drupal\mongodb_user;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Driver\fake\FakeConnection;
use Drupal\Core\Session\MetadataBag;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\Session\SessionManager as BaseSessionManager;
use Drupal\mongodb\MongoCollectionFactory;
use Symfony\Component\HttpFoundation\RequestStack;

class MongodbSessionManager extends BaseSessionManager {

  /**
   * @var MongoCollectionFactory
   */
  protected $mongo;

  public function __construct(RequestStack $request_stack, MongoCollectionFactory $mongo, MetadataBag $metadata_bag, SessionConfigurationInterface $session_configuration, $handler = NULL) {
    BaseSessionManager::__construct($request_stack, new FakeConnection([]), $metadata_bag, $session_configuration, $handler);
    $this->mongo = $mongo;
  }

  public function delete($uid) {
    // Nothing to do if we are not allowed to change the session.
    if (!$this->writeSafeHandler->isSessionWritable() || $this->isCli()) {
      return;
    }
    $this->mongo->get('sessions')->remove(array('uid' => (int) $uid));
  }

  protected function migrateStoredSession($old_session_id) {
    $criteria = ['sid' => Crypt::hashBase64($old_session_id)];
    $newobj = array('sid' => Crypt::hashBase64($this->getId()));
    $this->mongo->get('sessions')->update($criteria, $newobj);
  }

}
