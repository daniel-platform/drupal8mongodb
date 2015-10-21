<?php

/**
 * @file
 * Contains \Drupal\node\NodeGrantDatabaseStorage.
 */

namespace Drupal\mongodb_node;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mongodb\MongoCollectionFactory;
use Drupal\node\NodeGrantDatabaseStorageInterface;
use Drupal\node\NodeInterface;

/**
 * Defines a controller class that handles the node grants system.
 *
 * This is used to build node query access.
 */
class MongodbNodeGrantStorage implements NodeGrantDatabaseStorageInterface {

  /**
   * @var \Drupal\mongoDb\MongoCollectionFactory $mongo
   */
  protected $mongo;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The language manager
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a NodeGrantDatabaseStorage object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(MongoCollectionFactory $mongo, ModuleHandlerInterface $module_handler, LanguageManagerInterface $language_manager) {
    $this->mongo = $mongo;
    $this->moduleHandler = $module_handler;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function access(NodeInterface $node, $operation, $langcode, AccountInterface $account) {
    // If no module implements the hook or the node does not have an id there is
    // no point in querying the database for access grants.
    if (!$this->moduleHandler->getImplementations('node_grants') || !$node->id()) {
      // No opinion.
      return AccessResult::neutral();
    }
    $query['nid'] = (int) $node->id();
    $query['grant'][$operation]['$exists'] = TRUE;
    $node_access_grants = node_access_grants($operation, $account);
    $fallbacks = [$langcode == $node->language()->getId()];
    if ($grant_conditions = $this->buildGrantsQueryCondition($node_access_grants, $langcode, $fallbacks)) {
      $query['grant'][$operation]['$in'] = $grant_conditions;
    }
    if ($node->isPublished()) {
      $new_query['$or'] = [
        ['_id' => 0],
        $query,
      ];
      $query = $new_query;
    }
    // Only the 'view' node grant can currently be cached; the others currently
    // don't have any cacheability metadata. Hopefully, we can add that in the
    // future, which would allow this access check result to be cacheable in all
    // cases. For now, this must remain marked as uncacheable, even when it is
    // theoretically cacheable, because we don't have the necessary metadata to
    // know it for a fact.
    $set_cacheability = function (AccessResult $access_result) use ($operation) {
      $access_result->addCacheContexts(['user.node_grants:' . $operation]);
      if ($operation !== 'view') {
        $access_result->setCacheMaxAge(0);
      }
      return $access_result;
    };

    if ($this->mongo->get('entity.node')->findOne($query)) {
      return $set_cacheability(AccessResult::allowed());
    }
    else {
      return $set_cacheability(AccessResult::forbidden());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkAll(AccountInterface $account) {
    $query['nid'] = 0;
    $query['grant.view']['$exists'] = TRUE;
    $all_langcodes = $this->languageManager->getLanguages();
    $node_access_grants = node_access_grants('view', $account);
    if ($grant_conditions = $this->buildGrantsQueryCondition($node_access_grants, $all_langcodes)) {
      $query['grant.view']['$in'] = $grant_conditions;
    }
    return $this->mongo->get('entity.node')->find($query)->count();
  }

  /**
   * {@inheritdoc}
   */
  public function alterQuery($query, array $tables, $op, AccountInterface $account, $base_table) {
    if (!$langcode = $query->getMetaData('langcode')) {
      $langcode = FALSE;
    }

    // Find all instances of the base table being joined -- could appear
    // more than once in the query, and could be aliased. Join each one to
    // the node_access table.
    $grants = node_access_grants($op, $account);
    foreach ($tables as $nalias => $tableinfo) {
      $table = $tableinfo['table'];
      if (!($table instanceof SelectInterface) && $table == $base_table) {
        // Set the subquery.
        $subquery = $this->database->select('node_access', 'na')
          ->fields('na', array('nid'));

        // If any grant exists for the specified user, then user has access to the
        // node for the specified operation.
        $langcodes = $this->languageManager->getLanguages();
        $fallbacks = [0, 1];
        if ($this->languageManager->isMultilingual()) {
          if ($langcode === FALSE) {
            $fallbacks = [1];
          }
          else {
            $langcodes = [$langcode];
          }
        }
        $grant_conditions = $this->buildGrantsQueryCondition($grants, $langcodes, $fallbacks);

        // Attach conditions to the subquery for nodes.
        if (count($grant_conditions->conditions())) {
          $subquery->condition($grant_conditions);
        }
        $subquery->condition('na.grant_' . $op, 1, '>=');

        // Add langcode-based filtering if this is a multilingual site.
        if (\Drupal::languageManager()->isMultilingual()) {
          // If no specific langcode to check for is given, use the grant entry
          // which is set as a fallback.
          // If a specific langcode is given, use the grant entry for it.
          if ($langcode === FALSE) {
            $subquery->condition('na.fallback', 1, '=');
          }
          else {
            $subquery->condition('na.langcode', $langcode, '=');
          }
        }

        $field = 'nid';
        // Now handle entities.
        $subquery->where("$nalias.$field = na.nid");

        $query->exists($subquery);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function write(NodeInterface $node, array $grants, $realm = NULL, $delete = TRUE) {
    $collection = $this->mongo->get('entity.node');
    $query = ['nid' => $node->id()];
    $record = $collection->findOne($query) ?: ['grant' => []];
    $stored_grants = $record['grant'];
    $ops = ['view', 'update', 'delete'];
    if ($delete) {
      foreach ($ops as $op) {
        if ($realm) {
          $stored_grants[$op] = array_values(array_filter($stored_grants[$op], function ($grant) use ($realm) {
            return $grant['realm'] != $realm && $grant['realm'] != 'all';
          }));
        }
        else {
          unset($stored_grants[$op]);
        }
      }
    }
    // Only perform work when node_access modules are active.
    if (!empty($grants) && count($this->moduleHandler->getImplementations('node_grants'))) {
      // If we have defined a granted langcode, use it. But if not, add a grant
      // for every language this node is translated to.
      foreach ($grants as $grant) {
        if ($realm && $realm != $grant['realm']) {
          continue;
        }
        if (isset($grant['langcode'])) {
          $grant_languages = [$grant['langcode'] => $this->languageManager->getLanguage($grant['langcode'])];
        }
        else {
          $grant_languages = $node->getTranslationLanguages(TRUE);
        }
        foreach ($grant_languages as $grant_langcode => $grant_language) {
          foreach ($ops as $op) {
            if ($grant["grant_$op"] >= 1) {
              $stored_grants[$op][] = [
                'langcode' => $grant_langcode,
                'fallback' => (int) ($grant_langcode == $node->language()->getId()),
                'gid' => $grant['gid'],
                'realm' => $realm,
              ];
            }
          }
        }
      }
    }
    if ($record['grant'] !== $stored_grants) {
      $newobj['$set']['grant'] = $stored_grants;
      $collection->update($query, $newobj, ['upsert' => !$node->id()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    $this->mongo->get('entity.node')
      ->update(['grant' => ['$exists' => TRUE]], ['$unset' => ['grant' => '']], ['multi' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function writeDefault() {
    static::writeDefaultMongo($this->mongo);
  }

  /**
   * Write the default grant.
   *
   * @param MongoCollectionFactory $mongo
   */
  public static function writeDefaultMongo(MongoCollectionFactory $mongo) {
    $mongo->get('entity.node')->insert(['_id' => 0]);
  }

  /**
   * {@inheritdoc}
   */
  public function count() {
    $this->mongo->get('entity.node')
      ->count(['grant' => ['$exists' => TRUE]]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteNodeRecords(array $nids) {
  }

  /**
   * Creates a query condition from an array of node access grants.
   *
   * @param array $node_access_grants
   *   An array of grants, as returned by node_access_grants().
   * @return \Drupal\Core\Database\Query\Condition
   *   A condition object to be passed to $query->condition().
   *
   * @see node_access_grants()
   */
  protected function buildGrantsQueryCondition(array $node_access_grants, $langcodes, $fallbacks = [0, 1]) {
    $conditions = [];
    if (!is_array($langcodes)) {
      $langcodes = [$langcodes];
    }
    foreach ($node_access_grants as $realm => $gids) {
      if (!empty($gids)) {
        foreach ($gids as $gid) {
          foreach ($langcodes as $langcode) {
            foreach ($fallbacks as $fallback) {
              $conditions[] = [
                'langcode' => $langcode,
                'fallback' => $fallback,
                'gid' => $gid,
                'realm' => $realm,
              ];
            }
          }
        }
      }
    }
    return $conditions;
  }

}
