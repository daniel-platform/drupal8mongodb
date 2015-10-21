<?php

/**
 * @file
 * Contains \Drupal\mongodb_shortcut\MongodbShortcutSetStorage.
 */


namespace Drupal\mongodb_shortcut;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mongodb\MongoCollectionFactory;
use Drupal\shortcut\ShortcutSetInterface;
use Drupal\shortcut\ShortcutSetStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MongodbShortcutSetStorage extends ConfigEntityStorage implements ShortcutSetStorageInterface {

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $mongo;

  /**
   * Constructs a MongodbShortcutSetStorage object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_info
   *   The entity info for the entity type.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_service
   *   The UUID service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\mongodb\MongoCollectionFactory $mongo
   *   The mongodb collection factory.
   */
  public function __construct(EntityTypeInterface $entity_info, ConfigFactoryInterface $config_factory, UuidInterface $uuid_service, LanguageManagerInterface $language_manager, ModuleHandlerInterface $module_handler, MongoCollectionFactory $mongo) {
    parent::__construct($entity_info, $config_factory, $uuid_service, $language_manager);

    $this->moduleHandler = $module_handler;
    $this->mongo = $mongo;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_info) {
    return new static(
      $entity_info,
      $container->get('config.factory'),
      $container->get('uuid'),
      $container->get('language_manager'),
      $container->get('module_handler'),
      $container->get('mongo')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function assignUser(ShortcutSetInterface $shortcut_set, $account) {
    $newobj = [
      '_id' => $account->id(),
      'set_name' => $shortcut_set->id(),
    ];
    $this->mongo->get('shortcut_set_users')
      ->update(['_id' => $account->id()], $newobj, ['upsert' => TRUE]);
    drupal_static_reset('shortcut_current_displayed_set');
  }

  /**
   * {@inheritdoc}
   */
  public function unassignUser($account) {
    $this->mongo->get('shortcut_set_users')
      ->remove(['_id' => $account->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAssignedShortcutSets(ShortcutSetInterface $shortcut_set) {
    $this->mongo->get('shortcut_set_users')
      ->remove(['set_name' => $shortcut_set->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getAssignedToUser($account) {
    $set = $this->mongo->get('shortcut_set_users')
      ->findOne(['_id' => $account->id()], ['set_name' => 1]);
    return $set ? $set['set_name'] : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function countAssignedUsers(ShortcutSetInterface $shortcut_set) {
    return $this->mongo->get('shortcut_set_users')
      ->count(['set_name' => $shortcut_set->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultSet(AccountInterface $account) {
    // Allow modules to return a default shortcut set name. Since we can only
    // have one, we allow the last module which returns a valid result to take
    // precedence. If no module returns a valid set, fall back on the site-wide
    // default, which is the lowest-numbered shortcut set.
    $suggestions = array_reverse($this->moduleHandler->invokeAll('shortcut_default_set', array($account)));
    $suggestions[] = 'default';
    $shortcut_set = NULL;
    foreach ($suggestions as $name) {
      if ($shortcut_set = $this->load($name)) {
        break;
      }
    }

    return $shortcut_set;
  }

}
