<?php

/**
 * @file
 * Contains \Drupal\mongodb\CacheTagsChecksumMongodb.
 */

namespace Drupal\mongodb;

use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

class CacheTagsChecksumMongodb implements CacheTagsChecksumInterface, CacheTagsInvalidatorInterface {

  /**
   * The mongo collection factory
   *
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $mongo;

  /**
   * Contains already loaded cache invalidations from the database.
   *
   * @var array
   */
  protected $tagCache = array();

  /**
   * A list of tags that have already been invalidated in this request.
   *
   * Used to prevent the invalidation of the same cache tag multiple times.
   *
   * @var array
   */
  protected $invalidatedTags = array();

  /**
   * Constructs a DatabaseCacheTagsChecksum object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(MongoCollectionFactory $mongo) {
    $this->mongo = $mongo;
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags) {
    foreach ($tags as $tag) {
      // Only invalidate tags once per request unless they are written again.
      if (isset($this->invalidatedTags[$tag])) {
        continue;
      }
      $this->invalidatedTags[$tag] = TRUE;
      unset($this->tagCache[$tag]);
      $this->mongo->get('cachetags')
        ->update(['tag' => $tag], ['$inc' => ['invalidations' => 1]], ['upsert' => TRUE]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentChecksum(array $tags) {
    // Remove tags that were already invalidated during this request from the
    // static caches so that another invalidation can occur later in the same
    // request. Without that, written cache items would not be invalidated
    // correctly.
    foreach ($tags as $tag) {
      unset($this->invalidatedTags[$tag]);
    }
    return $this->calculateChecksum($tags);
  }

  /**
   * {@inheritdoc}
   */
  public function isValid($checksum, array $tags) {
    return $checksum == $this->calculateChecksum($tags);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateChecksum(array $tags) {
    $checksum = 0;

    $query_tags = array_values(array_diff($tags, array_keys($this->tagCache)));
    if ($query_tags) {
      $db_tags = [];
      $result = $this->mongo->get('cachetags')
        ->find(['tag' => ['$in' => $query_tags]]);
      foreach ($result as $tag) {
        $db_tags[$tag['tag']] = $tag['invalidations'];
      }
      $this->tagCache += $db_tags;
      // Fill static cache with empty objects for tags not found in the database.
      $this->tagCache += array_fill_keys(array_diff($query_tags, array_keys($db_tags)), 0);
    }

    foreach ($tags as $tag) {
      $checksum += $this->tagCache[$tag];
    }

    return $checksum;
  }

  /**
   * {@inheritdoc}
   */
  public function reset() {
    $this->tagCache = array();
    $this->invalidatedTags = array();
  }

}
