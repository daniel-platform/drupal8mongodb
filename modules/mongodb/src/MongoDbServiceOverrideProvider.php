<?php

/**
 * @file
 * Contains \Drupal\mongodb\MongoDbServiceOverrideProvider.
 */


namespace Drupal\mongodb;


use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;

class MongoDbServiceOverrideProvider implements ServiceProviderInterface {


  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    $container->setParameter('default_backend', 'mongodb');
    $kv = $container->getParameter('factory.keyvalue');
    $kv['default'] = 'keyvalue.mongodb';
    $container->setParameter('factory.keyvalue', $kv);
    $kve = $container->getParameter('factory.keyvalue.expirable');
    $kve['keyvalue_expirable_default'] = 'keyvalue.expirable.mongodb';
    $container->setParameter('factory.keyvalue.expirable', $kve);
    $container->setAlias('config.storage', 'mongodb.config.storage');
  }

}
