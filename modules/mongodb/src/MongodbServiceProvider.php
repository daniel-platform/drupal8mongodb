<?php

/**
 * @file
 * Definition of Drupal\mongodb\MongodbServiceProvider..
 */

namespace Drupal\mongodb;

use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * MongoDB service provider. Registers Mongo-related services.
 */
class MongodbServiceProvider implements ServiceProviderInterface, ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    $parameter_name = 'container.modules';
    $modules = $container->getParameter($parameter_name);
    if (!isset($modules['Drupal\\mongodb'])) {
      $modules['mongodb'] = [
        'type' => 'module',
        'pathname' => substr(dirname(__DIR__), strlen($container->get('app.root')) + 1) . '/mongodb.info.yml',
        'filename' => 'mongodb.module',
      ];
      $container->setParameter($parameter_name, $modules);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $mongodb_is_default = $container->hasParameter('default_backend') && $container->getParameter('default_backend') === 'mongodb';
    if ($container->hasDefinition('mongodb.session_manager') &&
         (($container->hasAlias('session_manager') && $container->getAlias('session_manager') === 'mongodb.session_manager') ||
           $mongodb_is_default)) {
      // DrupalKernel checks whether the session_manager is initialized and
      // aliases get initialized at their target so we need to replace the
      // definition instead of aliasing.
      $definition = $container->getDefinition('mongodb.session_manager');
      $container->removeAlias('session_manager');
      $container->setDefinition('session_manager', $definition);
      $container->removeDefinition('mongodb.session_manager');
    }
    if ($mongodb_is_default && $container->hasDefinition('block.repository')) {
      #$container->setAlias('block.repository', 'block.repository.mongodb');
    }
    static::createIndexes($container->get('mongo'));
  }

  /**
   * Ensures indexes on various Mongo collections.
   *
   * @param MongoCollectionFactory $mongo
   * @param array $settings
   */
  public static function createIndexes(MongoCollectionFactory $mongo, array $settings = NULL) {
    // Flood indexes.
    $mongo->get('flood')->ensureIndex(
      array(
        'event' => 1,
        'identifier' => 1,
        'timestamp' => 1,
        'expiration' => 1,
      )
    );
    if (isset($settings['flood']['ttl'])) {
      $ttl = $settings['flood']['ttl'];
    }
    else {
      $ttl = 300;
    }
    $mongo->get('flood')->ensureIndex(array('expiration' => 1), array('expireAfterSeconds' => $ttl));

    // File usage indexes
    $mongo->get('file_usage')->ensureIndex(array(
      'fid' => 1,
      'module' => 1,
      'type' => 1,
      'id' => 1,
      'count' => 1,
    ));
    $mongo->get('file_usage')->ensureIndex(array(
      'fid' => 1,
      'module' => 1,
      'count' => 1,
    ));
    $mongo->get('file_usage')->ensureIndex(array(
      'fid' => 1,
      'count' => 1,
    ));

    // Path alias. This should cover all queries via index intersections.
    $mongo->get('url_alias')->ensureIndex(array('alias' => 1));
    $mongo->get('url_alias')->ensureIndex(array('source' => 1));
    $mongo->get('url_alias')->ensureIndex(array('langcode' => 1, '_id' => 1));
    $mongo->get('url_alias')->ensureIndex(array('langcode' => -1, '_id' => 1));
  }

}
