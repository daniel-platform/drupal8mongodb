<?php

/**
 * @file
 * Definition of Drupal\mongodb/CacheBackendMongodb.
 */

namespace Drupal\mongodb;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;

/**
 * Defines MongoDB cache implementation.
 *
 * @see \Drupal\mongodb\CacheBackendMongodbFactory
 */
class CacheBackendMongodb implements CacheBackendInterface {

  /**
   * MongoDB collection.
   *
   * @var \MongoCollection
   */
  protected $collection;

  /**
   * @var \Drupal\Core\Cache\CacheTagsChecksumInterface
   */
  protected $checksumProvider;

  /**
   * Constructs a CacheBackendMongodb object.
   *
   * @param \MongoCollection $bin
   *   The cache bin MongoClient object for which the object is created.
   */
  public function __construct(\MongoCollection $collection, CacheTagsChecksumInterface $checksum_provider) {
    // All cache tables should be prefixed with 'cache_', except for the
    // default 'cache' bin.
    $this->collection = $collection;
    $this->checksumProvider = $checksum_provider;
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::get().
   *
   * Returns data from the persistent cache.
   *
   * @param string $cid
   *   The cache ID of the data to retrieve.
   * @param bool $allow_invalid
   *   (optional) If TRUE, a cache item may be returned even if it is expired or
   *   has been invalidated. Such items may sometimes be preferred, if the
   *   alternative is recalculating the value stored in the cache, especially
   *   if another concurrent request is already recalculating the same value.
   *   The "valid" property of the returned object indicates whether the item is
   *   valid or not. Defaults to FALSE.
   *
   * @return object|false
   *   The cache item or FALSE on failure.
   */
  public function get($cid, $allow_invalid = FALSE) {
    $cids = array($cid);
    $items = $this->getMultiple($cids, $allow_invalid);
    if (empty($items)) {
      return FALSE;
    }
    return current($items);
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::getMultiple().
   *
   * Returns data from the persistent cache when given an array of cache IDs.
   *
   * @param array $cids
   *   An array of cache IDs for the data to retrieve. This is passed by
   *   reference, and will have the IDs successfully returned from cache
   *   removed.
   * @param bool $allow_invalid
   *   (optional) If TRUE, cache items may be returned even if they have expired
   *   or been invalidated. Such items may sometimes be preferred, if the
   *   alternative is recalculating the value stored in the cache, especially
   *   if another concurrent thread is already recalculating the same value. The
   *   "valid" property of the returned objects indicates whether the items are
   *   valid or not. Defaults to FALSE.
   *
   * @return array
   *   An array of cache item objects indexed by cache ID.
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $find = array();
    $find['_id']['$in'] = array_map('strval', $cids);
    $result = $this->collection->find($find);
    $cache = array();
    foreach ($result as $item) {
      $item = $this->prepareItem((object) $item, $allow_invalid);
      if ($item) {
        $cache[$item->cid] = $item;
      }
    }
    $cids = array_diff($cids, array_keys($cache));
    return $cache;
  }

  /**
   * Prepares a cached item.
   *
   * Checks that items are either permanent or did not expire, and unserializes
   * data as appropriate.
   *
   * @param \stdClass $cache
   *   An item loaded from get() or getMultiple().
   *
   * @return mixed
   *   The item with data unserialized as appropriate or FALSE if there is no
   *   valid item to load.
   */
  protected function prepareItem($cache, $allow_invalid) {
    if (!isset($cache->data)) {
      return FALSE;
    }

    $cache->tags = $cache->tags ? explode(' ', $cache->tags) : array();

    // Check expire time.
    $cache->valid = $cache->expire instanceof \MongoDate ? $cache->expire->sec >= REQUEST_TIME : $cache->expire == CacheBackendInterface::CACHE_PERMANENT;

    // Check if invalidateTags() has been called with any of the items's tags.
    if (!$this->checksumProvider->isValid($cache->checksum, $cache->tags)) {
      $cache->valid = FALSE;
    }

    if (!$allow_invalid && !$cache->valid) {
      return FALSE;
    }

    if ($cache->data instanceof \MongoBinData) {
      $cache->data = $cache->data->bin;
    }

    // Unserialize and return the cached data.
    if ($cache->serialized) {
      $cache->data = unserialize($cache->data);
    }

    return $cache;
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::set().
   *
   * Stores data in the persistent cache.
   *
   * @param string $cid
   *   The cache ID of the data to store.
   * @param mixed $data
   *   The data to store in the cache.
   *   Some storage engines only allow objects up to a maximum of 1MB in size to
   *   be stored by default. When caching large arrays or similar, take care to
   *   ensure $data does not exceed this size.
   * @param int $expire
   *   One of the following values:
   *   - CacheBackendInterface::CACHE_PERMANENT: Indicates that the item should
   *     not be removed unless it is deleted explicitly.
   *   - A Unix timestamp: Indicates that the item will be considered invalid
   *     after this time, i.e. it will not be returned by get() unless
   *     $allow_invalid has been set to TRUE. When the item has expired, it may
   *     be permanently deleted by the garbage collector at any time.
   * @param array $tags
   *   An array of tags to be stored with the cache item. These should normally
   *   identify objects used to build the cache item, which should trigger
   *   cache invalidation when updated. For example if a cached item represents
   *   a node, both the node ID and the author's user ID might be passed in as
   *   tags. For example array('node' => array(123), 'user' => array(92)).
   */
  public function set($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = array()) {
    // We do not serialize configurations as we're sure we always get
    // them as arrays. This will be much faster as mongo knows how to
    // store arrays directly.
    $serialized = !is_scalar($data) && $this->collection->getName() != 'cache_config';
    $entry = array(
      '_id' => (string) $cid,
      'cid' => (string) $cid,
      'serialized' => $serialized,
      'created' => round(microtime(TRUE), 3),
      'expire' => $expire == CacheBackendInterface::CACHE_PERMANENT ? CacheBackendInterface::CACHE_PERMANENT : new \MongoDate($expire),
      'data' => $serialized ? serialize($data) : $data,
      'tags' => implode(' ', $tags),
      'checksum' => $this->checksumProvider->getCurrentChecksum($tags),
    );

    // Use MongoBinData for non-UTF8 strings.
    if (is_string($entry['data']) && !drupal_validate_utf8($entry['data'])) {
      $entry['data'] = new \MongoBinData($entry['data']);
    }

    try {
      $this->collection->save($entry, array('w' => 0));
    }
    catch (\Exception $e) {
      // The database may not be available, so we'll ignore cache_set requests.
    }
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::delete().
   *
   * Deletes an item from the cache.
   *
   * @param string $cid
   *   The cache ID to delete.
   */
  public function delete($cid) {
    $this->collection->remove(array('_id' => (string) $cid));
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::deleteMultiple().
   *
   * Deletes multiple items from the cache.
   *
   * @param array $cids
   *   An array of cache IDs to delete.
   */
  public function deleteMultiple(array $cids) {
    try {
      $remove = array('cid' => array('$in' => $cids));
      $this->collection->remove($remove, array('w' => 0));
    }
    catch (\Exception $e) {
      // The database may not be available.
    }
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::flush().
   *
   * Deletes all cache items in a bin.
   */
  public function deleteAll() {
    $this->collection->drop();
  }

  /**
   * Removes expired cache items from MongoDB.
   */
  public function expire() {
    // @OTDO: Since we use TTL collections do nothing here. Still?
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::garbageCollection().
   *
   * Performs garbage collection on a cache bin.
   * The backend may choose to delete expired or invalidated items.
   */
  public function garbageCollection() {
    $this->expire();
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::isEmpty().
   *
   * Checks if a cache bin is empty. A cache bin is considered empty
   * if it does not contain any valid data for any cache ID.
   *
   * @return
   *   TRUE if the cache bin specified is empty.
   */
  public function isEmpty() {
    $this->garbageCollection();
    $item = $this->collection->findOne();
    return empty($item);
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::invalidate().
   *
   * Marks a cache item as invalid. Invalid items may be returned in
   * later calls to get(), if the $allow_invalid argument is TRUE.
   *
   * @param string $cid
   *   The cache ID to invalidate.
   */
  public function invalidate($cid) {
    $this->invalidateMultiple(array($cid));
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::invalidateMultiple().
   *
   * Marks cache items as invalid. Invalid items may be returned in
   * later calls to get(), if the $allow_invalid argument is TRUE.
   *
   * @param string $cids
   *   An array of cache IDs to invalidate.
   */
  public function invalidateMultiple(array $cids) {
    try {
      $this->collection->update(
        array('_id' => array('$in' =>  array_map('strval', $cids))),
        array('$set' => array('expire' => new \MongoDate(REQUEST_TIME - 1))),
        array('w' => 0, 'multiple' => TRUE)
      );
    }
    catch (\Exception $e) {
      // The database may not be available.
    }
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::invalidateAll().
   *
   * Marks all cache items as invalid. Invalid items may be returned
   * in later calls to get(), if the $allow_invalid argument is TRUE.
   */
  public function invalidateAll() {
    try {
      $this->collection->update(
        array(),
        array('$set' => array('expire' => new \MongoDate(REQUEST_TIME - 1))),
        array('w' => 0, 'multiple' => TRUE)
      );
    }
    catch (\Exception $e) {
      // The database may not be available.
    }
  }

  /**
   * Implements Drupal\Core\Cache\CacheBackendInterface::removeBin().
   *
   * Remove a cache bin.
   */
  public function removeBin() {
    $this->collection->drop();
  }

  /**
   * Store multiple items in the persistent cache.
   *
   * @param array $items
   *   An array of cache items, keyed by cid. In the form:
   * @code
   *   $items = array(
   *     $cid => array(
   *       // Required, will be automatically serialized if not a string.
   *       'data' => $data,
   *       // Optional, defaults to CacheBackendInterface::CACHE_PERMANENT.
   *       'expire' => CacheBackendInterface::CACHE_PERMANENT,
   *       // (optional) The cache tags for this item, see CacheBackendInterface::set().
   *       'tags' => array(),
   *     ),
   *   );
   * @endcode
   */
  public function setMultiple(array $items) {
    // TODO: Implement setMultiple() method.
  }
}
