<?php

/**
 * @file
 * Contains Drupal\mongodb\Path.
 */

namespace Drupal\mongodb;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Path\AliasStorageInterface;

/**
 * Provides a class for CRUD operations on path aliases in MongoDB.
 */
class MongodbPathAliasStorage implements AliasStorageInterface {

  const ALIAS_COLLECTION = 'url_alias';

  /**
   * The object wrapping the MongoDB database object.
   *
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $mongo;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $module_handler;

  /**
   * Constructs a Path CRUD object.
   *
   * @param \Drupal\mongodb\MongoCollectionFactory $mongo
   *   The object wrapping the MongoDB database object.
   *
   * @param \Drupal\core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(MongoCollectionFactory $mongo, ModuleHandlerInterface $module_handler) {
    $this->mongo = $mongo;
    $this->module_handler = $module_handler;
  }

  /**
   * @return \MongoCollection
   */
  protected function mongoCollection() {
    $ret = $this->mongo->get(static::ALIAS_COLLECTION);
    return $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function save($source, $alias, $langcode = Language::LANGCODE_NOT_SPECIFIED, $pid = NULL) {

    $fields = array(
      'source' => $source,
      'alias' => $alias,
      'langcode' => $langcode,
    );

    if ($pid) {
      $hook = 'path_update';
    }
    else {
      $pid = $this->mongo->nextId();
      $hook = 'path_insert';
    }

    $response = $this->mongoCollection()->update(['_id' => $pid], ['$set' => $fields], ['upsert' => TRUE]);

    if (empty($response['err'])) {
      $fields['pid'] = $pid;
      $this->module_handler->invokeAll($hook, $fields);
      return $fields;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function load($conditions) {
    if (isset($conditions['pid'])) {
      $conditions['_id'] = intval($conditions['pid']);
      unset($conditions['pid']);
    }
    $result = $this->mongoCollection()->findOne($conditions);
    // $result will be NULL on failure, but PathInterface::load requires FALSE.
    if (empty($result)) {
      $result = FALSE;
    }
    else {
      // Code in core assumes the id is called "pid", not "_id".
      $result['pid'] = $result['_id'];
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function delete($conditions) {
    $path = $this->load($conditions);
    // Code in core assumes the id is called "pid", not "_id".
    if (isset($conditions['pid'])) {
      $conditions['_id'] = $conditions['pid'];
      unset($conditions['pid']);
    }
    $response = $this->mongoCollection()->remove($conditions);
    $this->module_handler->invokeAll('path_delete', $path);
    return $response['n'];
  }

  /**
   * {@inheritdoc}
   */
  public function preloadPathAlias($preloaded, $langcode) {
    $args['source']['$in'] = $preloaded;
    return $this->mongodbAliasQuery($args, $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function lookupPathAlias($path, $langcode) {
    $aliases = $this->mongodbAliasQuery(['source' => $path], $langcode, 1);
    return reset($aliases);
  }

  /**
   * {@inheritdoc}
   */
  public function lookupPathSource($path, $langcode) {
    if ($aliases = $this->mongodbAliasQuery(['alias' => $path], $langcode, 1)) {
      reset($aliases);
      return key($aliases);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function pathHasMatchingAlias($path_prefix) {
    $args['source']['$regex'] = new \MongoRegex('/^' . preg_quote($path_prefix, '/') . '/');
    return (bool) $this->mongodbAliasQuery($args, NULL, 1);
  }

  /**
   * @param $args
   * @param $langcode
   * @return array
   */
  protected function mongodbAliasQuery($args, $langcode = NULL, $limit = NULL) {
    if (isset($langcode)) {
      $args['langcode']['$in'] = [Language::LANGCODE_NOT_SPECIFIED];
      if ($langcode != Language::LANGCODE_NOT_SPECIFIED) {
        $args['langcode']['$in'][] = $langcode;
        $sort = ['langcode' => $langcode < Language::LANGCODE_NOT_SPECIFIED ? 1 : -1];
      }
    }

    $sort['_id'] = 1;
    $fields = ['source' => 1, 'alias' => 1];
    $cursor = $this->mongoCollection()->find($args, $fields)->sort($sort);
    if (isset($limit)) {
      $cursor->limit($limit);
    }
    $result = [];
    foreach ($cursor as $item) {
      $result[$item['source']] = $item['alias'];
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function aliasExists($alias, $langcode, $source = NULL) {
    $criteria = array(
      'alias' => $alias,
      'langcode' => $langcode,
    );
    if (!empty($source)) {
      $criteria['source'] = array('$ne' => $source);
    }
    return (bool) $this->mongoCollection()->count($criteria);
  }

  /**
   * Checks if there are any aliases with language defined.
   *
   * @return bool
   *   TRUE if aliases with language exist.
   */
  public function languageAliasExists() {
    return (bool) $this->mongoCollection()->count(['langcode' => Language::LANGCODE_NOT_SPECIFIED]);
  }

  /**
   * Loads aliases for admin listing.
   *
   * @param array $header
   *   Table header.
   * @param string $keys
   *   Search keys.
   * @return array
   *   Array of items to be displayed on the current page.
   */
  public function getAliasesForAdminListing($header, $keys = NULL) {
    if ($keys) {
      $criteria = array(
        // Replace wildcards with PCRE wildcards.
        // TODO check escaping reliability. Is should be more complex than this.
        // @see http://www.rgagnon.com/javadetails/java-0515.html
        'alias' => preg_replace('!\*+!', '.*', $keys),
      );
    }
    else {
      $criteria = array();
    }
    // TODO PagerSelectExtender
    // TODO TableSortExtender: should be shorter than doing this every time.
    $order = array();
    foreach ($header as $order_element) {
      // This happens on Operations column.
      if (!is_array($order_element)) {
        continue;
      }
      $direction = (isset($order_element['sort']) && strtolower($order_element['sort']) === 'desc') ? -1 : 1;
      $order[$order_element['field']] = $direction;
    }
    $alias_arrays = $this->mongoCollection()->find($criteria)->limit(50)->sort($order);
    $alias_objects = array();
    foreach ($alias_arrays as $alias_array) {
      // Code in core assumes the id is called "pid", not "_id".
      $alias_array['pid'] = $alias_array['_id'];
      $alias_objects[] = (object) $alias_array;
    }

    return $alias_objects;
  }
}
