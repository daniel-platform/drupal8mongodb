<?php

/**
 * @file
 * Contains Drupal\mongodb\Entity\ContentEntityStorage.
 */

namespace Drupal\mongodb\Entity;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryBase;
use Drupal\Core\Entity\Query\QueryFactoryInterface;
use Drupal\mongodb\MongoCollectionFactory;

class QueryFactory implements QueryFactoryInterface {

  public function __construct(MongoCollectionFactory $mongo) {
    $this->mongo = $mongo;
    $this->namespaces = QueryBase::getNamespaces($this);
  }

  /**
   * Instantiates an entity query for a given entity type.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param string $conjunction
   *   The operator to use to combine conditions: 'AND' or 'OR'.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   An entity query for a specific configuration entity type.
   */
  public function get(EntityTypeInterface $entity_type, $conjunction) {
    return new Query($this->mongo, $entity_type, $conjunction, $this->namespaces);
  }

  /**
   * Returns a aggregation query object for a given entity type.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param string $conjunction
   *   - AND: all of the conditions on the query need to match.
   *   - OR: at least one of the conditions on the query need to match.
   *
   * @throws \Drupal\Core\Entity\Query\QueryException
   * @return \Drupal\Core\Entity\Query\QueryAggregateInterface
   *   The query object that can query the given entity type.
   */
  public function getAggregate(EntityTypeInterface $entity_type, $conjunction) {
    // TODO: Implement getAggregate() method.
    return new QueryAggregate($this->mongo, $entity_type, $conjunction, $this->namespaces);
  }
}
