<?php

/**
 * @file
 * Contains \Drupal\mongodb\Tests\Menu\MenuTreeStorageTest.
 */

namespace Drupal\mongodb\Tests\Menu;

use Drupal\mongodb\MongodbMenuTreeStorage;
use Drupal\system\Tests\Menu\MenuTreeStorageTest;

/**
 * Tests the menu tree storage.
 *
 * @group Menu
 *
 * @see \Drupal\Core\Menu\MenuTreeStorage
 */
class MongodbMenuTreeStorageTest extends MenuTreeStorageTest {

  /**
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $mongo;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->mongo = $this->container->get('mongo');
    $this->treeStorage = new MongodbMenuTreeStorage($this->mongo, $this->container->get('cache.menu'), 'menu_tree');
    $this->installEntitySchema('menu_link_content');
  }

  protected function doTestTable() {

  }

  /**
   * Tests that a link's stored representation matches the expected values.
   *
   * @param string $id
   *   The ID of the menu link to test
   * @param array $expected_properties
   *   A keyed array of column names and values like has_children and depth.
   * @param array $parents
   *   An ordered array of the IDs of the menu links that are the parents.
   * @param array $children
   *   Array of child IDs that are visible (enabled == 1).
   */
  protected function assertMenuLink($id, array $expected_properties, array $parents = array(), array $children = array()) {
    $query = [];
    $query['id'] = $id;
    foreach ($expected_properties as $field => $value) {
      if (preg_match('/^p(\d+)$/', $field, $matches)) {
        $field = 'p.' . $matches[1];
      }
      $query[$field] = $value;
    }
    $collection = $this->mongo->get('menu_tree');
    $all = iterator_to_array($collection->find($query));
    $this->assertEqual(count($all), 1, "Found link $id matching all the expected properties");
    $raw = reset($all);

    // Put the current link onto the front.
    array_unshift($parents, $raw['id']);

    $query = $this->connection->select('menu_tree');
    $query->fields('menu_tree', array('id', 'mlid'));
    $query->condition('id', $parents, 'IN');
    $found_parents = [];
    $query = [];
    $query['id']['$in'] = $parents;
    foreach ($collection->find($query, ['id', 'mlid']) as $link) {
      $found_parents[$link['id']] = $link['mlid'];
    }
    $this->assertEqual(count($parents), count($found_parents), 'Found expected number of parents');
    $this->assertEqual($raw['depth'], count($found_parents), 'Number of parents is the same as the depth');

    $materialized_path = $this->treeStorage->getRootPathIds($id);
    $this->assertEqual(array_values($materialized_path), array_values($parents), 'Parents match the materialized path');
    // Check that the selected mlid values of the parents are in the correct
    // column, including the link's own.
    for ($i = $raw['depth']; $i >= 1; $i--) {
      $parent_id = array_shift($parents);
      $this->assertEqual($raw["p$i"], $found_parents[$parent_id], "mlid of parent matches at column p$i");
    }
    for ($i = $raw['depth'] + 1; $i <= $this->treeStorage->maxDepth(); $i++) {
      $this->assertEqual($raw["p$i"], 0, "parent is 0 at column p$i greater than depth");
    }
    if ($parents) {
      $this->assertEqual($raw['parent'], end($parents), 'Ensure that the parent field is set properly');
    }
    $found_children = array_keys($this->treeStorage->loadAllChildren($id));
    // We need both these checks since the 2nd will pass if there are extra
    // IDs loaded in $found_children.
    $this->assertEqual(count($children), count($found_children), "Found expected number of children for $id");
    $this->assertEqual(array_intersect($children, $found_children), $children, 'Child IDs match');
  }

}
