<?php

/**
 * @file
 * Contains \Drupal\mongodb_taxonomy\MongodbVocabularyStorage.
 */


namespace Drupal\mongodb_taxonomy;


use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\taxonomy\VocabularyStorageInterface;

class MongodbVocabularyStorage extends ConfigEntityStorage implements VocabularyStorageInterface {

  /**
   * {@inheritdoc}
   *
   * @todo: this is a cheat and returns every term. To be fixed when
   * hiearchy is added.
   */
  public function getToplevelTids($vids) {
    /** @var \MongoCollection $collection */
    $return = [];
    $collection = \Drupal::service('mongo')->get('entity.taxonomy_term');
    foreach ($collection->find(['bundle' => ['$in' => $vids]], ['_id' => TRUE]) as $row) {
      $return[] = $row['_id'];
    }
    return $return;
  }
}
