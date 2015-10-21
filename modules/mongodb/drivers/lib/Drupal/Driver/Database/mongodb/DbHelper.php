<?php

/**
 * @file
 * Contains \Drupal\Driver\Database\mongodb\TestQuery.
 */

namespace Drupal\Driver\Database\mongodb;

use Drupal\Core\Database\Driver\fake\FakeStatement;
use Drupal\mongodb\MongoCollectionFactory;
use Drupal\mongodb_node\MongodbNodeGrantStorage;
use Drupal\simpletest\TestBase;

class DbHelper {

  /**
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $mongo;
  protected $calls = [];
  protected $hash = [];

  public function __construct(MongoCollectionFactory $mongo) {
    $this->mongo = $mongo;
  }

  public function __call($name, $args) {
    if (in_array($name, ['query', 'select', 'update', 'insert', 'merge', 'delete', 'truncate'])) {
      $this->calls = [];
      $this->hash = [];
    }
    $this->calls[$name][] = $args;
    // Remove variables from various methods and use the results to identify
    // the query.
    if ($name == 'query') {
      $args = $args[0];
    }
    if ($name == 'condition') {
      unset($args[1]);
    }
    if (!isset($this->calls['select'])) {
      if ($name == 'fields' || $name == 'values') {
        $keys = array_keys($args[0]);
        // Is this a column => value array?
        if ($keys !== range(0, count($keys) - 1)) {
          // Keep the columns.
          $args = $keys;
        }
        elseif ($name == 'values') {
          // If it is just a list of values then discard the whole thing.
          $args = [];
        }
      }
    }
    $this->hash[$name][] = $args;
    $method = 'do' . substr(hash('sha256', serialize($this->hash)), 0, 8);
    if (method_exists($this, $method)) {
      return call_user_func([$this, $method]);
    }
    #file_put_contents('/tmp/log', "$name $method\n", FILE_APPEND);
    return $this;
  }

  /**
   * db_query()->fetchField() in NodeRevisionsTest::testRevisions()
   */
  protected function dofecbeda1() {
    $args = $this->calls['query'][0][1];
    return $this->mongo->get('entity_revision.node')
      ->count(['_id' => $args[':vid'], 'entity_id' => $args[':nid']]);
  }

  /**
   * db_update() in NodeRevisionsTest::testRevisions()
   */
  protected function do4b9334be() {
    $this->singleEntityUpdate('node', TRUE);
  }

  /**
   * db_select() in NodeRevisionsTest::testRevisions()
   */
  protected function doa6f0dfb0() {
    $nid = $this->calls['condition'][0][1];
    if ($result = $this->mongo->get('entity.node')->findOne(['_id' => $nid])) {
      return [$result['values'][0]['vid'][0]['value']];
    }
    $test_id = substr(drupal_valid_test_ua(), 10);
    TestBase::insertAssert($test_id, 'Drupal\node\Tests\NodeRevisionsTest', FALSE);
  }

  /**
   * db_query in NodeRevisionPermissionsTest.
   */
  protected function doc713ece0() {
    return $this->mongo->get('entity_revision.node')
      ->count(['entity_id' => $this->calls['query'][0][1][':nid']]);
  }

  /**
   * db_insert() in node_install()
   */
  protected function do525ab523() {
    MongodbNodeGrantStorage::writeDefaultMongo($this->mongo);
  }

  protected function singleEntityUpdate($entity_type_id, $is_revision = FALSE) {
    $entity_id = $this->calls['condition'][0][1];
    list($field, $value) = each($this->calls['fields'][0][0]);
    $prefix = $is_revision ? 'entity_revision' : 'entity';
    $this->mongo->get("$prefix.$entity_type_id")
      ->update(['_id' => $entity_id], ['$set' => ["values.0.$field.0.value" => $value]]);
  }
}
