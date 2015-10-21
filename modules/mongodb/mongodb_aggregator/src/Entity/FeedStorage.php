<?php

/**
 * @file
 * Contains Drupal\mongodb\Entity\FeedStorageController.
 */

namespace Drupal\mongodb_aggregator\Entity;

use Drupal\aggregator\FeedInterface;
use Drupal\aggregator\FeedStorageInterface;
use Drupal\mongodb\Entity\ContentEntityStorage;

class FeedStorage extends ContentEntityStorage implements FeedStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function getFeedIdsToRefresh() {
    $find['values']['$elemMatch'] = array(
      'queued' => 0,
      'checked_plus_refresh' => array('$lt' => REQUEST_TIME),
      'refresh' => array('$ne' => AGGREGATOR_CLEAR_NEVER)
    );
    return array_keys(iterator_to_array($this->mongo->get('entity.aggregator_feed')->find(array($find), array('_id' => TRUE))));
  }

  /**
   * {@inheritdoc}
   */
  protected function denormalize(array $translated_values) {
    $checked_plus_refresh = 0;
    foreach (array('checked', 'refresh') as $field) {
      if (!empty($translated_values[$field][0]['value'])) {
        $checked_plus_refresh += $translated_values[$field][0]['value'];
      }
    }
    $translated_values['checked_plus_refresh'][0]['value'] = $checked_plus_refresh;
    return parent::denormalize($translated_values);
  }

  /**
   * {@inheritdoc}
   *
   * @todo remove once https://drupal.org/node/2228733 is in.
   */
  public function getFeedDuplicates(FeedInterface $feed) {
    $query = \Drupal::entityQuery('aggregator_feed');

    $or_condition = $query->orConditionGroup()
      ->condition('title', $feed->label())
      ->condition('url', $feed->getUrl());
    $query->condition($or_condition);

    if ($feed->id()) {
      $query->condition('fid', $feed->id(), '<>');
    }

    return $this->loadMultiple($query->execute());
  }
}
