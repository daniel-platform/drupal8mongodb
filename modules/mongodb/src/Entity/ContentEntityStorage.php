<?php

/**
 * @file
 * Contains Drupal\mongodb\Entity\ContentEntityStorage.
 */

namespace Drupal\mongodb\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\mongodb\MongoCollectionFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;


class ContentEntityStorage extends ContentEntityStorageBase {

  /**
   * @var \Drupal\mongoDb\MongoCollectionFactory $mongo
   */
  protected $mongo;

  /**
   * @var EntityManagerInterface
   */
  protected $entityManager;

  /**
   * Constructs a DatabaseStorageController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection to be used.
   */
  public function __construct(EntityTypeInterface $entity_type, MongoCollectionFactory $mongo, EntityManagerInterface $entity_manager) {
    parent::__construct($entity_type);
    $this->mongo = $mongo;
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('mongo'),
      $container->get('entity.manager')
    );
  }

  /**
   * Load a specific entity revision.
   *
   * @param int $revision_id
   *   The revision id.
   *
   * @return \Drupal\Core\Entity\EntityInterface|false
   *   The specified entity revision or FALSE if not found.
   */
  public function loadRevision($revision_id) {
    $revisions = $this->loadFromMongo('entity_revision', ['_id' => (int) $revision_id]);
    return isset($revisions[$revision_id]) ? $revisions[$revision_id] : FALSE;
  }

  /**
   * Loads entity records from a MongoDB collections.
   *
   * @param string $prefix
   * @param array $find
   * @return array
   */
  protected function loadFromMongo($prefix, array $find) {
    $collection = $this->mongo->get($prefix . '.' . $this->entityType->id());
    $return = array();
    $langcode_key = $this->entityType->getKey('langcode');
    $default_langcode_key = $this->entityType->getKey('default_langcode');
    $revision_key = $this->entityType->getKey('revision');
    $translatable = $this->entityType->isTranslatable();
    foreach ($collection->find($find) as $record) {
      $data = array();
      $definitions = $this->entityManager->getFieldDefinitions($this->entityTypeId, $record['bundle']);
      $this->mongoToEntityData($data, $record, $definitions, $translatable, $default_langcode_key, $langcode_key);
      if ($prefix == 'entity_revision') {
        // Add non-revisionable data from the current revision.
        $entity_record = $this->mongo
          ->get('entity.' . $this->entityType->id())
          ->findOne(['_id' => $record['entity_id']]);
        $definitions = array_filter($this->entityManager->getFieldStorageDefinitions($this->entityTypeId), function (FieldStorageDefinitionInterface $definition) use ($revision_key) {
          return !$definition->isRevisionable() && $definition->getName() != $revision_key;
        });
        $this->mongoToEntityData($data, $entity_record, $definitions, $translatable, $default_langcode_key, $langcode_key);
        if ($entity_record['values'][0][$this->entityType->getKey('revision')][0]['value'] != $record['_id']) {
          $data['isDefaultRevision'][LanguageInterface::LANGCODE_DEFAULT] = FALSE;
        }
      }
      $return[$record['_id']] = new $this->entityClass($data, $this->entityTypeId, $record['bundle'], $record['translations']);
    }
    return $return;
  }

  /**
   * @param array $data
   *   The data to be passed to the entity constructor.
   * @param array $record
   *   The record as read from MongoDB.
   * @param array $definitions
   *   Field definition array.
   * @param bool $translatable
   *   Whether the entity is translatable.
   * @param string $default_langcode_key
   *   The default_langcode key, typically default_langcode.
   * @param $langcode_key
   *   The langcode key, typucally langcode.
   */
  protected function mongoToEntityData(array &$data, array $record, array $definitions, $translatable, $default_langcode_key, $langcode_key) {
    foreach ($record['values'] as $translation) {
      foreach ($definitions as $field_name => $definition) {
        if (isset($translation[$field_name])) {
          $index = $translatable && !$translation[$default_langcode_key][0]['value'] ?
            $translation[$langcode_key][0]['value'] :
            LanguageInterface::LANGCODE_DEFAULT;
          $data[$field_name][$index] = $translation[$field_name];
        }
      }
    }
  }

  /**
   * Delete a specific entity revision.
   *
   * A revision can only be deleted if it's not the currently active one.
   *
   * @param int $revision_id
   *   The revision id.
   */
  public function deleteRevision($revision_id) {
    $this->mongo->get('entity_revision.' . $this->entityType->id())
      ->remove(array('_id' => $revision_id));
  }

  /**
   * Deletes permanently saved entities.
   *
   * @param array $entities
   *   An array of entity objects to delete.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   In case of failures, an exception is thrown.
   */
  public function doDelete($entities) {
    if (!$entities) {
      // If no IDs or invalid IDs were passed, do nothing.
      return;
    }
    $ids = array('$in' => array_keys($entities));
    $this->mongo->get('entity.' . $this->entityType->id())
      ->remove(array('_id' => $ids));
    $this->mongo->get('entity_revision.' . $this->entityType->id())
      ->remove(array('entity_id' => $ids));
  }

  /**
   * {@inheritdoc}
   */
  protected function doSave($id, EntityInterface $entity) {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $id = $entity->id();
    $is_new = FALSE;
    if ($entity->isNew() || $id === NULL || $id === '') {
      $is_new = TRUE;
      if ($id === NULL || $id === '') {
        $entity->get($this->entityType->getKey('id'))->value = $this->nextId('entity', $id);
      }
    }
    if ($this->entityType->isRevisionable()) {
      $revision_key = $this->entityType->getKey('revision');
      if ($entity->isNewRevision() && !$entity->getRevisionId()) {
        $entity->get($revision_key)->value = $this->nextId('entity_revision');
      }
    }
    $this->invokeFieldMethod($is_new ? 'insert' : 'update', $entity);
    $data = $this->getDataToSave($entity);
    if ($entity->isDefaultRevision()) {
      $criteria['_id'] = $entity->id();
      if ($this->entityType->isRevisionable()) {
        $data['revision_id'] = $entity->get($revision_key)->value;
      }
      $collection = $this->mongoCollection($entity, 'entity');
      $collection->update($criteria, $criteria + $data, ['upsert' => TRUE]);
      $return = $collection->db->lastError();
    }
    if ($this->entityType->isRevisionable()) {
      $criteria['_id'] = $entity->getRevisionId();
      $criteria['entity_id'] = $entity->id();
      $collection = $this->mongoCollection($entity, 'entity_revision');
      $collection->update($criteria, $criteria + $data, ['upsert' => TRUE]);
      $entity->setNewRevision(FALSE);
      if (!isset($return)) {
        $return = $collection->db->lastError();
      }
    }
    if (!$is_new) {
      $this->invokeTranslationHooks($entity);
    }

    if (isset($collection) && empty($return['err'])) {
      return $return['updatedExisting'] ? SAVED_UPDATED : SAVED_NEW;
    }
    return FALSE;
  }

  /**
   * @param $prefix
   * @return int
   */
  protected function nextId($prefix, $existing_id = 0) {
    return $this->mongo->nextId("$prefix.$this->entityTypeId", (int) $existing_id);
  }


  /**
   * Collect the translations of an entity for saving.
   *
   * @param ContentEntityInterface $entity
   * @return array
   */
  protected function getDataToSave(ContentEntityInterface $entity) {
    $default_langcode = $entity->getUntranslated()->language()->getId();
    $values = array();
    $langcodes = [];
    $languages = $entity->getTranslationLanguages();
    foreach ($languages as $langcode => $language) {
      $translation = $entity->getTranslation($langcode);
      $translated_values = [];
      $langcodes[] = $langcode;
      /** @var \Drupal\Core\Field\FieldItemListInterface $items */
      foreach ($translation as $field_name => $items) {
        $field_storage_definition = $items->getFieldDefinition()->getFieldStorageDefinition();
        $columns = $field_storage_definition->getSchema()['columns'];
        $cardinality = $field_storage_definition->getCardinality();
        /** @var \Drupal\Core\Field\FieldItemListInterface $items */
        if (!$items->isEmpty()) {
          foreach ($items as $delta => $item) {
            if ($delta == $cardinality) {
              break;
            }
            /** @var \Drupal\Core\Field\FieldItemInterface $item */
            foreach ($item->toArray() as $column => $value) {
              if (isset($columns[$column])) {
                $translated_values[$field_name][$delta][$column] = MongoCollectionFactory::castValue($columns[$column]['type'], $value);
              }
            }
          }
        }
      }
      if ($default_langcode == $langcode) {
        array_unshift($values, $this->denormalize($translated_values));
      }
      else {
        $values[] = $this->denormalize($translated_values);
      }
    }
    $data = array(
      'bundle' => $entity->bundle(),
      'translations' => $langcodes,
      'values' => $values,
    );
    $this->entityToData($entity, $data);
    return $data;
  }

  /**
   * Add extra values from the entity to the data to be saved.
   *
   * @param ContentEntityInterface $entity
   * @param array $data
   *   Three keys:
   *   - bundle: the entity bundle.
   *   - translations: the list of entity languages
   *   - values: the actual entity values
   */
  protected function entityToData(ContentEntityInterface $entity, &$data) {
  }

  /**
   * Gets the name of the service for the query for this entity storage.
   *
   * @return string
   *   The name of the service for the query for this entity storage.
   */
  public function getQueryServicename() {
    return 'entity.query.mongodb';
  }

  /**
   * @param array $translated_values
   * @return array
   */
  protected function denormalize(array $translated_values) {
    return $translated_values;
  }

  /**
   * {@inheritdoc}
   */
  protected function doLoadFieldItems($entities, $age) {
  }

  /**
   * {@inheritdoc}
   */
  protected function doSaveFieldItems(EntityInterface $entity, $update) {
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeleteFieldItems(EntityInterface $entity) {
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeleteFieldItemsRevision(EntityInterface $entity) {
  }

  /**
   * {@inheritdoc}
   */
  protected function readFieldItemsToPurge(FieldDefinitionInterface $field_definition, $batch_size) {
    // TODO: Implement readFieldItemsToPurge() method.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function purgeFieldItems(ContentEntityInterface $entity, FieldDefinitionInterface $field_definition) {
    // TODO: Implement purgeFieldItems() method.
  }

  /**
   * Performs storage-specific loading of entities.
   *
   * Override this method to add custom functionality directly after loading.
   * This is always called, while self::postLoad() is only called when there are
   * actual results.
   *
   * @param array|null $ids
   *   (optional) An array of entity IDs, or NULL to load all entities.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Associative array of entities, keyed on the entity ID.
   */
  protected function doLoadMultiple(array $ids = NULL) {
    $find = array();
    if ($ids !== NULL) {
      $find['_id']['$in'] = array_values(array_map('intval', $ids));
    }
    return $this->loadFromMongo('entity', $find);
  }

  /**
   * Determines if this entity already exists in storage.
   *
   * @param int|string $id
   *   The original entity ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @return bool
   */
  protected function has($id, EntityInterface $entity) {
    return !$entity->isNew();
  }

  /**
   * Determines the number of entities with values for a given field.
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $storage_definition
   *   The field for which to count data records.
   * @param bool $as_bool
   *   (Optional) Optimises the query for checking whether there are any records
   *   or not. Defaults to FALSE.
   *
   * @return bool|int
   *   The number of entities. If $as_bool parameter is TRUE then the
   *   value will either be TRUE or FALSE.
   *
   * @see \Drupal\Core\Entity\FieldableEntityStorageInterface::purgeFieldData()
   */
  public function countFieldData($storage_definition, $as_bool = FALSE) {
    // TODO: Implement countFieldData() method.
  }

  /**
   * @param EntityInterface $entity
   * @return \MongoCollection
   */
  protected function mongoCollection(ContentEntityInterface $entity, $prefix) {
    return $this->mongo->get($prefix . '.' . $this->entityTypeId);
  }

  /**
   * {@inheritdoc}
   */
  protected function buildPropertyQuery(QueryInterface $entity_query, array $values) {
    $default_langcode_key = $this->entityType->getKey('default_langcode');
    if ($default_langcode_key) {
      if (!array_key_exists($default_langcode_key, $values)) {
        if ($this->entityType->isTranslatable()) {
          $values[$default_langcode_key] = 1;
        }
      }
      // If the 'default_langcode' flag is explicitly not set, we do not care
      // whether the queried values are in the original entity language or not.
      elseif ($values[$default_langcode_key] === NULL) {
        unset($values[$default_langcode_key]);
      }
    }
    parent::buildPropertyQuery($entity_query, $values);
  }

}
