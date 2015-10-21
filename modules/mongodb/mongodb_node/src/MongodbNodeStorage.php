<?php

/**
 * @file
 * Contains \Drupal\node\MongodbNodeStorage.
 */

namespace Drupal\mongodb_node;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mongodb\Entity\ContentEntityStorage;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;

class MongodbNodeStorage extends ContentEntityStorage implements NodeStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function revisionIds(NodeInterface $node) {
    return $this->revisionQueryIds(['entity_id' => $node->id()]);
  }

  public function userRevisionIds(AccountInterface $account) {
    return $this->revisionQueryIds(['values.uid' => $account->id()]);
  }

  protected function revisionQueryIds(array $query) {
    $results = $this->mongo->get('entity_revision.node')
      ->find($query, ['_id' => 1])
      ->sort(['_id' => 1]);
    return array_map(function ($row) { return $row['_id'];}, iterator_to_array($results));
  }

  /**
   * Updates all nodes of one type to be of another type.
   *
   * @param string $old_type
   *   The current node type of the nodes.
   * @param string $new_type
   *   The new node type of the nodes.
   *
   * @return int
   *   The number of nodes whose node type field was modified.
   */
  public function updateType($old_type, $new_type) {
    $n = count(\Drupal::languageManager()->getLanguages());
    $newobj['$set']['bundle'] = $new_type;
    for ($i = 0; $i < $n; $i++) {
      $newobj['$set']["values.$i.type.0.value"] = $new_type;
    }
    $this->mongo->get('entity.node')
      ->update(['bundle' => $old_type], $newobj, ['multi' => TRUE]);
  }

  /**
   * Unsets the language for all nodes with the given language.
   *
   * @param \Drupal\Core\Language\LanguageInterface $language
   *  The language object.
   */
  public function clearRevisionsLanguage(LanguageInterface $language) {
    $newobj['$set'] = ['values.$.langcode.0.value' => LanguageInterface::LANGCODE_NOT_SPECIFIED];
    $query = ['values.langcode.0.value' => $language->getId()];
    $this->mongo->get('entity_revision.node')
      ->update($query, $newobj, ['multi' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function countDefaultLanguageRevisions(NodeInterface $node) {
    return $this->mongo->get('entity_revision.node')
      ->find(['values.langcode.0.value' => LanguageInterface::LANGCODE_NOT_SPECIFIED])
      ->count();
  }
}
