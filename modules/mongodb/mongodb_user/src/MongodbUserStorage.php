<?php

/**
 * @file
 * Definition of Drupal\mongodb_user\MongodbUserStorage.
 */

namespace Drupal\mongodb_user;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mongodb\Entity\ContentEntityStorage;
use Drupal\user\UserInterface;
use Drupal\user\UserStorageInterface;

class MongodbUserStorage extends ContentEntityStorage implements UserStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function updateLastLoginTimestamp(UserInterface $account) {
    $this->mongo->get('entity.user')
      ->update(['_id' => $account->id()], ['$set' => ['login' => $account->getLastLoginTime()]]);
  }

  /**
   * {@inheritdoc}
   */
  public function updateLastAccessTimestamp(AccountInterface $account, $timestamp) {
    $this->mongo->get('entity.user')
      ->update(['_id' => $account->id()], ['$set' => ['access' => $timestamp]]);
  }

  protected function doSave($id, EntityInterface $entity) {
    if ($id === 1) {
      $this->mongo->get('sequences')
        ->update(['_id' => 'entity.user'], ['$setOnInsert' => ['seq' => 1]], ['upsert' => TRUE]);
    }
    return parent::doSave($id, $entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function entityToData(ContentEntityInterface $account, &$data) {
    /** @var \Drupal\user\UserInterface $account */
    $definitions = $this->entityManager->getFieldStorageDefinitions($this->entityTypeId);
    $translation = $account->getUntranslated();
    foreach ($definitions as $field_name => $definition) {
      if (count($definition->getColumns()) == 1) {
        $value = [];
        foreach ($translation ->get($field_name) as $item) {
          $item_value = $item->getValue();
          $value[] = reset($item_value);
        }
        if ($definition->getCardinality() == 1) {
          $value = reset($value);
        }
        $data['session'][$field_name] = $value;
      }
    }
  }

  /**
   * Delete role references.
   *
   * @param array $rids
   *   The list of role IDs being deleted. The storage should
   *   remove permission and user references to this role.
   */
  public function deleteRoleReferences(array $rids) {
    // TODO: Implement deleteRoleReferences() method.
  }

}
