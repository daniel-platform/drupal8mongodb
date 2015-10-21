<?php

/**
 * @file
 * Definition of Drupal\mongodb\Config\MongoStorage.
 */

namespace Drupal\mongodb;

use Drupal\Core\Config\StorageInterface;

class MongodbConfigStorage implements StorageInterface {

  /**
   * The object wrapping the MongoDB database object.
   *
   * @var MongoCollectionFactory
   */
  protected $mongo;

  /**
   * The mongo collection name.
   *
   * @var string
   */
  protected $mongoCollectionName;

  /**
   * The prefix, typically 'config' or 'config.staging'.
   *
   * @var string
   */
  protected $prefix;

  /**
   * Constructs a new ConfigStorage controller.
   *
   * @param MongoCollectionFactory $mongo
   *   The object wrapping the MongoDB database object.
   * @param string $collection
   *   Name of the config collection.
   */
  public function __construct(MongoCollectionFactory $mongo, $prefix = 'config', $collection = StorageInterface::DEFAULT_COLLECTION) {
    $this->mongo = $mongo;
    $this->prefix = $prefix;
    $this->mongoCollectionName = $prefix . ($collection ?: '');
  }

  /**
   * {@inheritdoc}
   */
  public function exists($name) {
    return $this->mongoCollection()->count(array('_id' => $name)) ? TRUE : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function read($name) {
    $result = $this->mongoCollection()->findOne(array('_id' => $name));
    if (empty($result)) {
      return FALSE;
    }

    unset($result['_id']);
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function readMultiple(array $names) {
    $data = $this->mongoCollection()->find(array('_id' => array('$in' => array_values($names))));

    $list = array();
    foreach ($data as $item) {
      $list[$item['_id']] = $item;
      unset($list[$item['_id']]['_id']);
    }
    return $list;
  }

  /**
   * {@inheritdoc}
   */
  public function write($name, array $data) {
    try {
      $this->mongoCollection()->update(array('_id' => $name), array('$set' => $data), array('upsert' => TRUE));
    }
    catch (\Exception $e) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function delete($name) {
    try {
      $result = $this->mongoCollection()->remove(array('_id' => $name));
    }
    catch (\Exception $e) {
      return FALSE;
    }

    return $result['n'] == 0 ? FALSE : TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function rename($name, $new_name) {
    try {
      $collection = $this->mongoCollection();
      $item = $collection->findOne(array('_id' => $name));
      if (empty($item)) {
        return FALSE;
      }
      $item['_id'] = $new_name;
      $result = $collection->insert($item);
      if (!empty($result['err'])) {
	      return FALSE;
      }
      $collection->remove(array('_id' => $name));
    }
    catch (\Exception $e) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function encode($data) {
    // WTF is this part of general StorageInterface if it is only needed for
    // file-based backends?
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function decode($data) {
    // WTF is this part of general StorageInterface if it is only needed for
    // file-based backends?
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function listAll($prefix = '') {
    $condition = array();
    if (!empty($prefix)) {
      $condition = array('_id' => new \MongoRegex('/^' . str_replace('.', '\.', $prefix) . '/'));
    }

    $names = array();
    $result = $this->mongoCollection()->find($condition, array('_id' => TRUE));
    foreach ($result as $item) {
      $names[] = $item['_id'];
    }

    return $names;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll($prefix = '') {
    $condition = array();
    if (!empty($prefix)) {
      $condition = array('_id' => new \MongoRegex('/^' . str_replace('.', '\.', $prefix) . '/'));
    }

    try {
      $this->mongoCollection()->remove($condition);
    }
    catch (\Exception $e) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function createCollection($collection) {
    return new static($this->mongo, $this->prefix, $collection);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllCollectionNames() {
    return preg_grep('/^config\./', $this->mongo->get('config')->db->getCollectionNames());
  }

  /**
   * {@inheritdoc}
   */
  public function getCollectionName() {
    return $this->mongoCollectionName;
  }

  /**
   * @return \MongoCollection
   */
  protected function mongoCollection() {
    return $this->mongo->get($this->mongoCollectionName);
  }

}
