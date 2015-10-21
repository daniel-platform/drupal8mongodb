<?php
/**
 * @file
 * Logger functionality (watchdog).
 *
 * @license General Public License version 2 or later
 */

namespace Drupal\mongodb\Logger;

use Drupal\mongodb\MongoCollectionFactory;
use Psr\Log\AbstractLogger;

/**
 * MongoDB logger implementation for watchdog().
 *
 * @package Drupal\mongodb\Logger
 */
class Logger extends AbstractLogger {

  const TEMPLATE_COLLECTION = 'watchdog';
  const EVENT_COLLECTION_PREFIX = 'watchdog_event_';
  const EVENT_COLLECTIONS_PATTERN = '/watchdog_event_[[:xdigit:]]{32}$/';

  /**
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $collection_factory;

  /**
   * @var \MongoCollection
   */
  protected $message_templates;

  /**
   * @param \Drupal\mongodb\MongoCollectionFactory $collection_factory
   */
  public function __construct(MongoCollectionFactory $collection_factory) {
    $this->collection_factory = $collection_factory;
    $this->message_templates = $collection_factory->get(static::TEMPLATE_COLLECTION);
  }

  /**
   * TODO implement this method to replace the code in mongodb_watchdog().
   *
   * @param mixed $level
   * @param string $message
   * @param array $context
   *
   * @return null|void
   */
  public function log($level, $message, array $context = array()) {
    echo "Logging $level";
    var_dump($message);
  }

  /**
   * @return \MongoCollection[]
   *
   */
  public function listCollections() {
    return $this->templatesCollection()->db->listCollections();
  }

  /**
   * @param $template_id
   *
   * @return \MongoCollection
   */
  public function eventCollection($template_id) {
    $collection_name = static::EVENT_COLLECTION_PREFIX . $template_id;
    assert('preg_match(static::EVENT_COLLECTIONS_PATTERN, $collection_name)');
    return $this->collection_factory->get($collection_name);
  }

  /**
   * @return \MongoCollection
   */
  public function templatesCollection() {
    return $this->message_templates;
  }

  /**
   * First index is on <line, timestamp> instead of <function, line, timestamp>,
   * because we write to this collection a lot, and the smaller index on two
   * numbers should be much faster to create than one with a string included.
   */
  public function ensureIndexes() {
    $templates = $this->message_templates;
    $indexes = array(
      // Index for adding/updating increments.
      array(
        'line' => 1,
        'timestamp' => -1
      ),
      // Index for admin page without filters.
      array(
        'timestamp' => -1
      ),
      // Index for admin page filtering by type.
      array(
        'type' => 1,
        'timestamp' => -1
      ),
      // Index for admin page filtering by severity.
      array(
        'severity' => 1,
        'timestamp' => -1
      ),
      // Index for admin page filtering by type and severity.
      array(
        'type' => 1,
        'severity' => 1,
        'timestamp' => -1
      ),
    );

    foreach ($indexes as $index) {
      $templates->ensureIndex($index);
    }
  }

  /**
   * Load a MongoDB watchdog event.
   *
   * @param string $id
   *
   * @return object|FALSE
   */
  function eventLoad($id) {
    $result = $this->templatesCollection()->findOne(array('_id' => $id));
    return $result ? $result : FALSE;
  }

  /**
   * Drop the logger collections.
   *
   * @return int
   *   The number of collections dropped.
   */
  public function uninstall() {
    $count = 0;

    $collections = $this->listCollections();
    foreach ($collections as $collection) {
      $name = $collection->getName();
      if (preg_match(static::EVENT_COLLECTIONS_PATTERN, $name)) {
        $status = $collection->drop();
        if ($status['ok'] == 1) {
          ++$count;
        }
      }
    }

    $status = $this->message_templates->drop();
    if ($status['ok'] == 1)  {
      ++$count;
    }

    return $count;
  }
}
