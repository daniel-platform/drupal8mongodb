<?php

/**
 * @file
 * Contains \Drupal\aggregator\ItemStorage.
 */

namespace Drupal\mongodb_aggregator\Entity;

use Drupal\aggregator\FeedInterface;
use Drupal\aggregator\ItemStorageInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\mongodb\Entity\ContentEntityStorage;

/**
 * This is 100% identical to the core item storage class, should be removed if
 * core removes.
 */
class ItemStorage extends ContentEntityStorage implements ItemStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function getItemCount(FeedInterface $feed) {
    $query = \Drupal::entityQuery('aggregator_item')
      ->condition('fid', $feed->id())
      ->count();

    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function loadAll($limit = NULL) {
    $query = \Drupal::entityQuery('aggregator_item');
    return $this->executeFeedItemQuery($query, $limit);
  }

  /**
   * {@inheritdoc}
   */
  public function loadByFeed($fid, $limit = NULL) {
    $query = \Drupal::entityQuery('aggregator_item')
      ->condition('fid', $fid);
    return $this->executeFeedItemQuery($query, $limit);
  }

  /**
   * Helper method to execute an item query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The query to execute.
   * @param int $limit
   *   (optional) The number of items to return.
   *
   * @return \Drupal\aggregator\ItemInterface[]
   *   An array of the feed items.
   */
  protected function executeFeedItemQuery(QueryInterface $query, $limit) {
    $query->sort('timestamp', 'DESC')
      ->sort('iid', 'DESC');
    if (!empty($limit)) {
      $query->pager($limit);
    }

    return $this->loadMultiple($query->execute());
  }

}
