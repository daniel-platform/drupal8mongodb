<?php

namespace Drupal\mongodb\Tests\Entity;

use Drupal\system\Tests\Entity\EntityQueryTest as EntityQueryTestBase;

/**
 * Tests the basic MongoDB Entity API.
 *
 * @group Entity
 */
class EntityQueryTest extends EntityQueryTestBase {

  static $modules = array('mongodb');

  protected function assertIdentical($first, $second, $message = '', $group = 'Other') {
    if (is_array($second)) {
      $second = array_map('intval', $second);
    }
    return parent::assertIdentical($first, $second, $message, $group);
  }

  public function testMetadata() {

  }
}
