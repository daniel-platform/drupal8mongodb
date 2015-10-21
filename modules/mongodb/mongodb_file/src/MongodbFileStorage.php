<?php

/**
 * @file
 * Contains \Drupal\mongodb_file\MongodbFileStorage.
 */


namespace Drupal\mongodb_file;


use Drupal\file\FileStorageInterface;
use Drupal\mongodb\Entity\ContentEntityStorage;

class MongodbFileStorage extends ContentEntityStorage implements FileStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function spaceUsed($uid = NULL, $status = FILE_STATUS_PERMANENT) {
    $pipeine = [];
    if (isset($uid)) {
      $pipeine[] = ['$match' => ['values.uid.value' => $uid]];
    }
    $pipeine[] = ['$group' => ['total' => ['$sum' => '$values.0.filesize.value']], '_id' => 'x'];
    return $this->mongo->get('entity.file')->aggregate($pipeine)['total'];
  }
}
