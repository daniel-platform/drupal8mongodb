<?php

/**
 * @file
 * Contains Drupal\mongodb\Entity\ContentEntityStorage.
 */

namespace Drupal\mongodb\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryBase;
use Drupal\Core\Entity\Query\QueryException;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\mongodb\MongoCollectionFactory;

class Query extends QueryBase implements QueryInterface {

  public function __construct(MongoCollectionFactory $mongo, EntityTypeInterface $entity_type, $conjuction, array $namespaces) {
    parent::__construct($entity_type, $conjuction, $namespaces);
    $this->mongo = $mongo;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    try {
      $find = $this->condition->compile($this->entityType);
    }
    catch (QueryException $e) {
      return array();
    }
    $prefix = $this->allRevisions ? 'entity_revision' : 'entity';
    $fields = ['_id' => 1, 'entity_id' => 1, 'revision_id' => 1];
    $cursor = $this->mongo->get($prefix . '.' . $this->entityType->id())->find($find, $fields);
    if ($this->count) {
      return $cursor->count();
    }
    if ($this->sort) {
      foreach ($this->sort as $sort) {
        $mongo_sort['values.'. $sort['field']] = strtoupper($sort['direction']) == 'ASC' ? 1 : -1;
      }
      file_put_contents('/tmp/sort', json_encode($mongo_sort) . "\n", FILE_APPEND);
      $cursor->sort($mongo_sort);
    }
    if ($this->range) {
      $cursor->skip($this->range['start']);
      $cursor->limit($this->range['length']);
    }
    $return = array();
    foreach ($cursor as $id => $record) {
      $key = isset($record['revision_id']) ? $record['revision_id']: $record['_id'];
      $value = isset($record['entity_id']) ? $record['entity_id'] : $record['_id'];
      $return[$key] = $value;
    }
    return $return;
  }
}
