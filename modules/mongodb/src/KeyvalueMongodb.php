<?php

namespace Drupal\mongodb;

use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\KeyValueStore\StorageBase;

/**
 * This class holds a MongoDB key-value backend.
 */
class KeyvalueMongodb extends StorageBase implements KeyValueStoreExpirableInterface {

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
  protected $mongo_collection;

  /**
   * Construct this object.
   *
   * @param MongoCollectionFactory $mongo
   *   The object wrapping the MongoDB database object.
   * @param $collection
   *   Name of the key-value collection.
   */
  function __construct(MongoCollectionFactory $mongo, $collection) {
    parent::__construct($collection);
    $this->mongo = $mongo;
    $this->mongo_collection =  "keyvalue.$this->collection";
  }

  /**
   * Gets the collection for this key-value collection.
   *
   * @return \MongoCollection
   */
  protected function collection() {
    return $this->mongo->get($this->mongo_collection);
  }

  /**
   * Prepares an object for insertion.
   */
  protected function getObject($key, $value, $expire = NULL) {
    $scalar = is_scalar($value);
    $object = array(
      '_id' => (string) $key,
      'value' => $scalar ? $value : serialize($value),
      'scalar' => $scalar,
    );

    if (!empty($expire)) {
      $object['expire'] = new \MongoDate(REQUEST_TIME + $expire);
    }

    return $object;
  }

  /**
   * Saves a value for a given key with a time to live.
   *
   * @param string $key
   *   The key of the data to store.
   * @param mixed $value
   *   The data to store.
   * @param int $expire
   *   The time to live for items, in seconds.
   */
  function setWithExpire($key, $value, $expire) {
    $this->collection()->update(array('_id' => (string) $key), $this->getObject($key, $value, $expire), array('upsert' => TRUE));
  }

  /**
   * Sets a value for a given key with a time to live if it does not yet exist.
   *
   * @param string $key
   *   The key of the data to store.
   * @param mixed $value
   *   The data to store.
   * @param int $expire
   *   The time to live for items, in seconds.
   *
   * @return bool
   *   TRUE if the data was set, or FALSE if it already existed.
   */
  function setWithExpireIfNotExists($key, $value, $expire) {
    try {
      return $this->collection()->insert($this->getObject($key, $value, $expire));
    }
    catch (\MongoCursorException $e) {
      return FALSE;
    }
  }

  /**
   * Saves an array of values with a time to live.
   *
   * @param array $data
   *   An array of data to store.
   * @param int $expire
   *   The time to live for items, in seconds.
   */
  function setMultipleWithExpire(array $data, $expire) {
    foreach ($data as $key => $value) {
      $this->setWithExpire($key, $value, $expire);
    }
  }

  /**
   * Returns the stored key/value pairs for a given set of keys.
   *
   * @param array $keys
   *   A list of keys to retrieve.
   *
   * @return array
   *   An associative array of items successfully returned, indexed by key.
   *
   * @todo What's returned for non-existing keys?
   */
  public function getMultiple(array $keys) {
    return $this->getHelper($keys);
  }

  /**
   * Returns all stored key/value pairs in the collection.
   *
   * @return array
   *   An associative array containing all stored items in the collection.
   */
  public function getAll() {
    return $this->getHelper();
  }

  /**
   * Executes the get for getMultiple() and getAll().
   *
   * @param array|null $keys
   * @return array
   */
  protected function getHelper($keys = NULL) {
    $find = array();
    if ($keys) {
      $find['_id'] = array('$in' => $this->strMap($keys));
    }

    $find['$or'] = array(
      array('expire' => array('$gte' => new \MongoDate())),
      array('expire' => array('$exists' => FALSE)),
    );

    $result = $this->collection()->find($find);
    $return = array();
    foreach ($result as $row) {
      $return[$row['_id']] = $row['scalar'] ? $row['value'] : unserialize($row['value']);
    }
    return $return;
  }

  /**
   * Saves a value for a given key.
   *
   * @param string $key
   *   The key of the data to store.
   * @param mixed $value
   *   The data to store.
   */
  public function set($key, $value) {
    $this->collection()->update(array('_id' => (string) $key), $this->getObject($key, $value), array('upsert' => TRUE));
  }

  /**
   * Saves a value for a given key if it does not exist yet.
   *
   * @param string $key
   *   The key of the data to store.
   * @param mixed $value
   *   The data to store.
   *
   * @return bool
   *   TRUE if the data was set, FALSE if it already existed.
   */
  public function setIfNotExists($key, $value) {
    try {
      return $this->collection()->insert($this->getObject($key, $value));
    }
    catch (\MongoCursorException $e) {
      return FALSE;
    }
  }

  /**
   * Deletes multiple items from the key/value store.
   *
   * @param array $keys
   *   A list of item names to delete.
   */
  public function deleteMultiple(array $keys) {
    $this->collection()->remove(array('_id' => array('$in' => $this->strMap($keys))));
  }

  /**
   * Deletes all items from the key/value store.
   */
  public function deleteAll() {
    $this->collection()->remove(array());
  }

  /**
   * Cast keys to strings.
   */
  protected function strMap($keys) {
    return array_map('strval', $keys);
  }

  /**
   * Returns whether a given key exists in the store.
   *
   * @param string $key
   *   The key to check.
   *
   * @return bool
   *   TRUE if the key exists, FALSE otherwise.
   */
  public function has($key) {
    return (bool) $this->collection()->findOne(array('_id' => (string) $key));
  }

  /**
   * Renames a key.
   *
   * @param string $key
   *   The key to rename.
   * @param string $new_key
   *   The new key name.
   */
  public function rename($key, $new_key) {
    if ($key != $new_key && ($current = $this->get($key))) {
      unset($current['_id']);
      $this->set($new_key, $current);
    }
    $this->delete($key);
  }
}
