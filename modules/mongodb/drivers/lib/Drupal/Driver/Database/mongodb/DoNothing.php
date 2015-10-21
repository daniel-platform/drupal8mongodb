<?php

/**
 * @file
 * Contains \Drupal\Driver\Database\mongodb\DoNothing.
 */

namespace Drupal\Driver\Database\mongodb;

class DoNothing {

  function __call($name, $args) {
    // Nothing to do.
  }

}
