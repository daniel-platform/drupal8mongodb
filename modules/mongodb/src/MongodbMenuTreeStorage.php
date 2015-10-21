<?php

/**
 * @file
 * Contains \Drupal\mongodb\MongodbMenuTreeStorage .
 */

namespace Drupal\mongodb;

use Drupal\Component\Utility\String;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Driver\fake\FakeConnection;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Menu\MenuTreeStorage;

class MongodbMenuTreeStorage extends MenuTreeStorage {

  function __construct(MongoCollectionFactory $mongo, CacheBackendInterface $menu_cache_backend, CacheTagsInvalidatorInterface $cache_tags_invalidator, $table, array $options = array()) {
    parent::__construct(new FakeConnection([]), $menu_cache_backend, $cache_tags_invalidator, $table);
    $this->collection = $table;
    $this->mongo = $mongo;
  }

  /**
   * {@inheritdoc}
   */
  public function loadByRoute($route_name, array $route_parameters = array(), $menu_name = NULL) {
    // Sort the route parameters so that the query string will be the same.
    asort($route_parameters);
    // Since this will be urlencoded, it's safe to store and match against a
    // text field.
    // @todo Standardize an efficient way to load by route name and parameters
    //   in place of system path. https://www.drupal.org/node/2302139
    $param_key = $route_parameters ? UrlHelper::buildQuery($route_parameters) : '';
    $fields = array_map(function ($x) { return "value.$x";}, $this->definitionFields());
    $query = [
      'value.route_name' => $route_name,
      'value.route_param_key' => $param_key,
    ];
    if ($menu_name) {
      $query['value.menu_name'] = $menu_name;
    }
    // Make the ordering deterministic.
    $sort = ['value.depth' => 1, 'value.weight' => 1, 'value.id' => 1];
    $loaded = [];
    foreach ($this->mongoCollection()->find($query, $fields)->sort($sort) as $link) {
      $loaded[$link['value']['id']] = $this->prepareLink($link['value']);
    }
    return $loaded;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadLinks($menu_name, MenuTreeParameters $parameters) {
    $query = [];

    // Allow a custom root to be specified for loading a menu link tree. If
    // omitted, the default root (i.e. the actual root, '') is used.
    if ($parameters->root !== '') {
      $root = $this->loadFull($parameters->root);

      // If the custom root does not exist, we cannot load the links below it.
      if (!$root) {
        return array();
      }
      // When specifying a custom root, we only want to find links whose
      // parent IDs match that of the root; that's how we ignore the rest of the
      // tree. In other words: we exclude everything unreachable from the
      // custom root.
      $query['value.p'] = new \MongoRegex('/^' . preg_quote($root['p'], '/') .'/');

      // When specifying a custom root, the menu is determined by that root.
      $menu_name = $root['menu_name'];

      // If the custom root exists, then we must rewrite some of our
      // parameters; parameters are relative to the root (default or custom),
      // but the queries require absolute numbers, so adjust correspondingly.
      if (isset($parameters->minDepth)) {
        $parameters->minDepth += $root['depth'];
      }
      else {
        $parameters->minDepth = $root['depth'];
      }
      if (isset($parameters->maxDepth)) {
        $parameters->maxDepth += $root['depth'];
      }
    }

    // If no minimum depth is specified, then set the actual minimum depth,
    // depending on the root.
    if (!isset($parameters->minDepth)) {
      if ($parameters->root !== '' && !empty($root)) {
        $parameters->minDepth = $root['depth'];
      }
      else {
        $parameters->minDepth = 1;
      }
    }
    $query['value.menu_name'] = $menu_name;

    if (!empty($parameters->expandedParents)) {
      $query['value.parent']['$in'] = array_values($parameters->expandedParents);
    }
    if (isset($parameters->minDepth) && $parameters->minDepth > 1) {
      $query['value.depth']['$gte'] = $parameters->minDepth;
    }
    if (isset($parameters->maxDepth)) {
      $query['value.depth']['$lte'] = $parameters->maxDepth;
    }
    // Add custom query conditions, if any were passed.
    if (!empty($parameters->conditions)) {
      // Only allow conditions that are testing definition fields.
      $parameters->conditions = array_intersect_key($parameters->conditions, array_flip($this->definitionFields()));
      foreach ($parameters->conditions as $column => $value) {
        $query["value.$column"] = $value;
      }
    }

    $links = [];
    foreach ($this->mongoCollection()->find($query)->sort(['value.p' => 1]) as $link) {
      $links[$link['value']['id']] = $link['value'];
    }

    return $links;
  }

  /**
   * {@inheritdoc}
   */
  public function getExpanded($menu_name, array $parents) {
    $id_query['value.menu_name'] = $menu_name;
    $id_query['value.id']['$in'] = array_values($parents);
    $ps = [];
    foreach ($this->mongoCollection()->find($id_query, ['value.p' => 1]) as $link) {
      $ps[] = preg_quote($link['value']['p'], '/') . '.';
    }
    $query['value.menu_name'] = $menu_name;
    $query['value.expanded'] = 1;
    $query['value.has_children'] = 1;
    $query['value.enabled'] = 1;
    $query['value.p'] = new \MongoRegex('/^' . implode('|', $ps) . '/');
    return $this->getIds($query);
  }

  protected function doSave(array $link) {
    $original = $this->loadFull($link['id']);
    // @todo Should we just return here if the link values match the original
    //   values completely?
    //   https://www.drupal.org/node/2302137
    $affected_menus = array();

    if ($original) {
      $mlid = $original['mlid'];
      $link['has_children'] = $original['has_children'];
      $affected_menus[$original['menu_name']] = $original['menu_name'];
    }
    else {
      // Generate a new mlid.
      $mlid = $this->mongo->nextId('menu_links');
    }
    $link['mlid'] = $mlid;
    $fields = $this->preSave($link, $original);
    $fields['mlid'] = $mlid;
    // We may be moving the link to a new menu.
    $affected_menus[$fields['menu_name']] = $fields['menu_name'];
    $newobj['$set']['value'] = $fields;
    $this->mongoCollection()->update(['value.mlid' => $fields['mlid']], $newobj, ['upsert' => TRUE]);
    if ($original) {
      $this->updateParentalStatus($original);
    }
    $this->updateParentalStatus($link);
    return $affected_menus;
  }

  /**
   * {@inheritdoc}
   */
  protected function setParents(array &$fields, $parent, array $original) {
    if ($parent) {
      $fields['depth'] = $parent['depth'] + 1;
      $prefix = $parent['p'];
    }
    else {
      $fields['depth'] = 1;
      $prefix = '';
    }
    $fields['p'] = $prefix . $this->encode128($fields['mlid']);
  }

  /**
   * {@inheritdoc}
   */
  protected function encode128($number) {
    $encoded = $this->doEncode128($number);
    return $this->doEncode128(strlen($encoded)) . $encoded;
  }

  /**
   * @param $number
   * @return string
   */
  protected function doEncode128($number) {
    $encoded = '';
    while ($number) {
      $encoded = chr($number & 0x7F) . $encoded;
      $number = $number >> 7;
    }
    return $encoded;
  }

  /**
   * @param $encoded
   * @return array
   */
  protected function decode128($encoded) {
    $i = 0;
    $numbers = [];
    while (isset($encoded[$i])) {
      $number = 0;
      $current_end = $i + ord($encoded[$i]);
      for ($i++; $i <= $current_end; $i++) {
        $number = ($number << 7) + ord($encoded[$i]);
      }
      $numbers[] = $number;
    }
    return $numbers;
  }

  /**
   * {@inheritdoc}
   */
  protected function updateParentalStatus(array $link) {
    // If parent is empty, there is nothing to update.
    if (!empty($link['parent'])) {
      $query = [
        'value.menu_name' => $link['menu_name'],
        'value.parent' => $link['parent'],
        'value.enabled' => 1,
      ];
      $update['$set']['value.has_children'] = (int) (bool) $this->mongoCollection()->findOne($query, []);
      $this->mongoCollection()->update(['value.id' => $link['parent']], $update);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function findNoLongerExistingLinks(array $definitions) {
    $result = [];
    if ($definitions) {
      $find['value.id']['$nin'] = array_keys($definitions);
      $find['value.discovered'] = 1;
      foreach ($this->mongoCollection()->find($find, ['value.id'])->sort(['value.depth' => -1]) as $link) {
        $id = $link['value']['id'];
        $result[$id] = $id;
      }
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadFullMultiple(array $ids) {
    $loaded = [];
    $query['value.id']['$in'] = array_values($ids);
    foreach ($this->mongoCollection()->find($query) as $link) {
      $link = $link['value'];
      foreach ($this->serializedFields() as $name) {
        $link[$name] = unserialize($link[$name]);
      }
      $loaded[$link['id']] = $link;
    }
    return $loaded;
  }

  /**
   * {@inheritdoc}
   */
  public function getMenuNames() {
    $menu_names = $this->mongoCollection()->distinct('menu_name');
    return array_combine($menu_names, $menu_names);
  }

  /**
   * {@inheritdoc}
   */
  public function getRootPathIds($id) {
    $p = $this->mongoCollection()->findOne(['value.id' => $id], ['value.p' => 1]);
    if ($p) {
      $query['value.mlid']['$in'] = $this->decode128($p['value']['p']);
      return $this->getIds($query, ['value.depth' => -1]);
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function doFindChildrenRelativeDepth(array $original) {
    $max_depth = 0;
    foreach ($this->mongoCollection()->find($this->getChildrenQuery($original), ['value.depth'])->sort(['value.depth' => -1])->limit(1) as $link) {
      if ($link['value']['depth'] > $original['depth']) {
        $max_depth = $link['value']['depth'] - $original['depth'];
      }
    }
    return $max_depth;
  }

  /**
   * Re-parents a link's children when the link itself is moved.
   *
   * @param array $fields
   *   The changed menu link.
   * @param array $original
   *   The original menu link.
   */
  protected function moveChildren($fields, $original) {
    $query = $this->getChildrenQuery($original);
    $shift = $fields['depth'] - $original['depth'];
    $hex = function ($string) {
      return '\x' . implode('\x', str_split(bin2hex($string), 2));
    };
    $map = new \MongoCode("function () {
      this.value['p'] = this.value['p'].replace('". $hex($original['p']) . "', '" . $hex($fields['p']) . "');
      this.value['menu_name'] = '" . $fields['menu_name'] . "';
      this.value['depth'] += $shift;
      emit(this._id, this.value);
    }");
    $collection = $this->mongoCollection();
    $reduce = new \MongoCode("function () { }");
    $collection_name = $collection->getName();
    $collection->db->command([
      'mapreduce' => $collection_name,
      'map' => $map,
      'reduce' => $reduce,
      'out' => ['merge' => $collection_name],
      'query' => $query,
      'sort' => ['_id' => 1],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids) {
    $missing_ids = array_diff($ids, array_keys($this->definitions));

    if ($missing_ids) {
      $query['value.id']['$in'] = array_values($missing_ids);
      foreach ($this->mongoCollection()->find($query) as $link) {
        $link = $link['value'];
        $this->definitions[$link['id']] = $this->prepareLink($link);
      }
    }
    return array_intersect_key($this->definitions, array_flip($ids));
  }

  /**
   * {@inheritdoc}
   */
  public function loadByProperties(array $properties) {
    $query = [];
    foreach ($properties as $name => $value) {
      if (!in_array($name, $this->definitionFields(), TRUE)) {
        $fields = implode(', ', $this->definitionFields());
        throw new \InvalidArgumentException(String::format('An invalid property name, @name was specified. Allowed property names are: @fields.', array('@name' => $name, '@fields' => $fields)));
      }
      $query["value.$name"] = $value;
    }
    $loaded = [];
    foreach ($this->mongoCollection()->find($query) as $link) {
      $loaded[$link['value']['id']] = $this->prepareLink($link['value']);
    }
    return $loaded;
  }

  /**
   * {@inheritdoc}
   */
  public function menuNameInUse($menu_name) {
    $query = $this->connection->select($this->table, $this->options);
    $query->addField($this->table, 'mlid');
    $query->condition('menu_name', $menu_name);
    $query->range(0, 1);
    return (bool) $this->safeExecuteSelect($query);
  }

  /**
   * {@inheritdoc}
   */
  public function countMenuLinks($menu_name = NULL) {
    $query = [];
    if ($menu_name) {
      $query['menu_name'] = $menu_name;
    }
    return $this->mongoCollection()->count($query);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllChildIds($id) {
    $root = $this->loadFull($id);
    if (!$root) {
      return array();
    }
    return $this->getIds($this->getChildrenQuery($root));
  }

  /**
   * Returns a query to find the children of a link but not the link itself.
   *
   * @param $link
   * @return array
   */
  protected function getChildrenQuery($link) {
    $query['value.menu_name'] = $link['menu_name'];
    $query['value.p'] = new \MongoRegex('/^' . preg_quote($link['p'], '/') . './');
    return $query;
  }

  /**
   * @param array $query
   * @param array|null $sort
   * @return array
   */
  protected function getIds(array $query, array $sort = NULL) {
    $cursor = $this->mongoCollection()->find($query, ['value.id' => 1]);
    if (isset($sort)) {
      $cursor->sort($sort);
    }
    $ids = [];
    foreach ($cursor as $link) {
      $ids[$link['value']['id']] = $link['value']['id'];
    }
    return $ids;
  }

  /**
   * @return \MongoCollection
   */
  protected function mongoCollection() {
    return $this->mongo->get('menu_links');
  }

}
