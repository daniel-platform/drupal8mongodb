<?php

/**
 * @file
 * Contains \Drupal\mongodb_user\MongodbSessionHandler.
 */

namespace Drupal\mongodb_user;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Driver\fake\FakeConnection;
use Drupal\Core\Session\SessionHandler;
use Drupal\Core\Session\UserSession;
use Drupal\Core\Site\Settings;
use Drupal\Core\Utility\Error;
use Drupal\mongodb\MongoCollectionFactory;
use Symfony\Component\HttpFoundation\RequestStack;

class MongodbSessionHandler extends SessionHandler implements \SessionHandlerInterface {

  /**
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $mongo;

  /**
   * @param RequestStack $request_stack
   * @param MongoCollectionFactory $mongo
   */
  public function __construct(RequestStack $request_stack, MongoCollectionFactory $mongo) {
    $this->requestStack = $request_stack;
    $this->mongo = $mongo;
    $this->connection = new FakeConnection([]);
  }

  /**
   * {@inheritdoc}
   */
  public function read($sid) {
    global $_session_user;

    // Handle the case of first time visitors and clients that don't store
    // cookies (eg. web crawlers).
    $cookies = $this->requestStack->getCurrentRequest()->cookies;
    if (empty($sid) || !$cookies->has($this->getName())) {
      $_session_user = new UserSession();
      return '';
    }

    // Try to load a session using the non-HTTPS session id.
    $values = $this->findOne(['sid' => Crypt::hashBase64($sid)]);

    // We found the client's session record and they are an authenticated,
    // active user.
    if ($values && $values['uid'] > 0 && $values['status'] == 1) {
      $values['roles'][] = DRUPAL_AUTHENTICATED_RID;
      $_session_user = new UserSession($values);
    }
    elseif ($values) {
      // The user is anonymous or blocked. Only preserve two fields from the
      // {sessions} table.
      $_session_user = new UserSession(array(
        'session' => $values['session'],
        'access' => $values['access'],
      ));
    }
    else {
      // The session has expired.
      $_session_user = new UserSession();
    }

    return $_session_user->session;
  }

  /**
   * Tries to find a session and an according user.
   *
   * @param $conditions
   * @return array|mixed|null
   */
  protected function findOne($conditions) {
    $session = $this->mongoCollection()->findOne($conditions);
    if ($session) {
      $query['values.default_langcode.value'] = 1;
      $query['_id'] = $session['uid'];
      if ($user_record = $this->mongo->get('entity.user')->findOne($query, ['session'])) {
        $session += $user_record['session'];
      }
      else {
        $session = FALSE;
      }
    }
    return $session;
  }

  /**
   * {@inheritdoc}
   */
  public function write($sid, $value) {
    $user = \Drupal::currentUser();

    // The exception handler is not active at this point, so we need to do it
    // manually.
    try {
      // sid will be added from $key below.
      $fields = array(
        'uid' => $user->id(),
        'hostname' => $this->requestStack->getCurrentRequest()->getClientIP(),
        'session' => $value,
        'timestamp' => REQUEST_TIME,
      );
      $key = array('sid' => Crypt::hashBase64($sid));
      $this->mongoCollection()->update($key, $key + $fields, array('upsert' => TRUE));
      // Remove obsolete sessions.
      $this->cleanupObsoleteSessions();

      // Likewise, do not update access time more than once per 180 seconds.
      if ($user->isAuthenticated() && REQUEST_TIME - $user->getLastAccessedTime() > Settings::get('session_write_interval', 180)) {
        /** @var \Drupal\user\UserStorageInterface $storage */
        $storage = \Drupal::entityManager()->getStorage('user');
        $storage->updateLastAccessTimestamp($user, REQUEST_TIME);
      }
      return TRUE;
    }
    catch (\Exception $exception) {
      require_once DRUPAL_ROOT . '/core/includes/errors.inc';
      // If we are displaying errors, then do so with no possibility of a
      // further uncaught exception being thrown.
      if (error_displayable()) {
        print '<h1>Uncaught exception thrown in session handler.</h1>';
        print '<p>' . Error::renderExceptionSafe($exception) . '</p><hr />';
      }
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function close() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function destroy($sid) {
    $this->removeSession($sid);
    return parent::destroy($sid);
  }

  /**
   * {@inheritdoc}
   */
  public function gc($lifetime) {
    // Be sure to adjust 'php_value session.gc_maxlifetime' to a large enough
    // value. For example, if you want user sessions to stay in your database
    // for three weeks before deleting them, you need to set gc_maxlifetime
    // to '1814400'. At that value, only after a user doesn't log in after
    // three weeks (1814400 seconds) will his/her session be removed.
    $condition['timestamp']['$lt'] = REQUEST_TIME - $lifetime;
    $this->mongoCollection()->remove($condition);
    return TRUE;
  }

  /**
   * Remove a session from the database.
   */
  protected function removeSession($sid) {
    $this->mongoCollection()->remove(['sid' => Crypt::hashBase64($sid)]);
  }

  /**
   * Remove sessions marked for garbage collection.
   */
  protected function cleanupObsoleteSessions() {
    array_walk($this->obsoleteSessionIds, [$this, 'removeSession']);
  }

  /**
   * return \MongoCollection
   */
  protected function mongoCollection() {
    return $this->mongo->get('sessions');
  }

}
