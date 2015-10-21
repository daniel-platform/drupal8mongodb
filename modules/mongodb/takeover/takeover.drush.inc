<?php

use Drupal\Core\DependencyInjection\Container;

function takeover_drush_command() {
  $commands['takeover'] = array(
    'description' => 'Take over certain services',
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_FULL,
    'arguments' => array(
      'source' => 'Source service name.',
      'target' => 'Target service name.',
    ),
  );
  return $commands;
}

function drush_takeover_takeover($source_id, $target_id) {
  $classname = 'Drupal\takeover\Takeover' . Container::camelize($source_id);
  if (class_exists($classname)) {
    $classname::takeover(Drupal::service($source_id), Drupal::service($target_id));
  }

}
