<?php

/**
 * @file
 * Contains \Drupal\takeover\TakeoverConfigStorageActive.
 */

namespace Drupal\takeover;

use Drupal\Core\Config\StorageInterface;

/**
 * Copies config storage.
 */
class TakeoverConfig_Storage {

  /**
   * {@inheritdoc}
   */
  public static function takeover(StorageInterface $source, StorageInterface $destination) {
    foreach ($source->listAll() as $name) {
      $destination->write($name, $source->read($name));
    }
    foreach ($source->getAllCollectionNames() as $collection_name) {
      $collection_destination = $destination->createCollection($collection_name);
      $collection_source = $source->createCollection($collection_name);
      foreach ($collection_source->listAll() as $name) {
        $collection_destination->write($name, $collection_source->read($name));
      }
    }
  }

}
