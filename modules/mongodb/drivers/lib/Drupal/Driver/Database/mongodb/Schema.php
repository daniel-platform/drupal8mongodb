<?php

namespace Drupal\Driver\Database\mongodb;

class Schema extends DoNothing {

  public function tableExists() {
    // This is only used for install and we only need to tell apart
    // install_begin_request() from install_verify_database_ready().
    return count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)) == 3;
  }

  public function findTables() {
    // Simpletest needs this.
    return [];
  }

  public function dropTable($table) {

  }

}
