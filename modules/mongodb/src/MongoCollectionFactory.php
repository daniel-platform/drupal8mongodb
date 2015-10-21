<?php

/**
 * @file
 * Definition of Drupal\mongodb\MongodbBundle.
 */

namespace Drupal\mongodb;

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;

/**
 * Creates mongo collections based on settings.
 */
class MongoCollectionFactory {

  /**
   * var @array
   */
  protected $serverInfo;

  /**
   * var @array
   */
  protected $collectionInfo;

  /**
   * @var \MongoClient[]
   */
  protected $clients;

  /**
   * @var array;
   */
  protected $collections;

  /**
   * The simpletest prefix during testing.
   *
   * @var string
   */
  protected $testDatabasePrefix;

  /**
   * @param array $mongo
   */
  function __construct(array $mongo) {
    $mongo += array('servers' => array());
    $this->serverInfo = $mongo['servers'];
    // The default server needs to exist.
    $this->serverInfo += array('default' => array());
    foreach ($this->serverInfo as &$server) {
      $server += array(
        // The default server connection string.
        'server' => 'mongodb://localhost:27017',
        'options' => array(),
      );
      // By default, connect immediately.
      $server['options'] += array('connect' => TRUE);
    }
    // The default database for the default server is 'drupal'.
    $this->serverInfo['default'] += array('db' => 'drupal');
    $this->collectionInfo = isset($mongo['collections']) ? $mongo['collections'] : array();
  }

  /**
   * Factory method for this class.
   *
   * @param Settings $settings
   *
   * @return static
   */
  public static function create(Settings $settings) {
    if ($mongo = $settings->get('mongo', [])) {
      return new static($mongo);
    }
    return static::createFromDatabase();
  }

  /**
   * @return static
   */
  public static function createFromDatabase() {
    $db_info = Database::getConnectionInfo()['default'];
    if ($db_info['driver'] == 'mongodb') {
      $mongo['servers']['default']['server'] = $db_info['database'];
      return new static($mongo);
    }
    throw new \Exception("Can't initialize mongo.");
  }

  /**
   * @param string|array $collection_name
   *   If it is an array, the first element contains the prefix, and the second
   *   contains the actual collection name.
   * @return \MongoCollection
   */
  public function get($collection_name) {
    // Avoid something. collection names if NULLs are passed in.
    $args = array_filter(func_get_args());
    if (is_array($args[0])) {
      list($collection_name, $prefixed) = $args[0];
      $prefixed .= $collection_name;
    }
    else {
      $prefixed = implode('.', $args);
      if ($this->testDatabasePrefix = drupal_valid_test_ua()) {
        $prefixed = $this->testDatabasePrefix . $prefixed;
      }
    }
    if (!isset($this->clients[$collection_name])) {
      $server = $this->getServer($collection_name);
      $this->collections[$collection_name] = $this->getClient($server)
        ->selectCollection($server['db'], str_replace('system.', 'system_.', $prefixed));
    }
    return $this->collections[$collection_name];
  }

  /**
   * Runs a command on the same server where a collection is.
   *
   * @param string $collection_name
   * @param array $command
   * @return array
   */
  public function command($collection_name, array $command) {
    return $this->getClient($this->getServer($collection_name))->command($command);
  }

  /**
   * Cast a value according to a SQL type.
   */
  public static function castValue($sql_type, $value) {
    if (is_array($value)) {
      $value = array_values($value);
    }
    switch ($sql_type) {
      case 'int':
      case 'serial':
        return is_array($value) ? array_map('intval', $value) : intval($value);
      case 'float':
        return is_array($value) ? array_map('floatval', $value) : floatval($value);
      default:
        return $value;
    }
  }

  /**
   * Returns the server for a given collection.
   *
   * @param $collection_name
   * @return array
   */
  protected function getServer($collection_name) {
    $server_index = isset($this->collectionInfo[$collection_name]) ? $this->collectionInfo[$collection_name] : 'default';
    return $this->serverInfo[$server_index];
  }

  /**
   * @return \MongoClient
   */
  protected function getClient($server) {
    $connection_string = $server['server'] . '/' . $server['db'];
    if (!isset($this->clients[$connection_string])) {

/*	  echo "<pre>\n===============\n";
	  print_r($connection_string);
	  echo "\n===============\n";
	  print_r($server);
  	  echo "===============\n<pre>\n";
*/
      $client = new \MongoClient($connection_string, $server['options']);
      if (!empty($server['read_preference'])) {
        $client->setReadPreference($server['read_preference']);
      }
      $this->clients[$connection_string] = $client;
    }
    return $this->clients[$connection_string];
  }

  /**
   * Remove test collections when a test run ends.
   *
   * The class is held alive by the container of the test.
   */
  public function __destruct() {
    if ($this->testDatabasePrefix && !drupal_valid_test_ua()) {
      foreach ($this->serverInfo as $server) {
        $connection_string = $server['server'];
        if (isset($this->clients[$connection_string])) {
          /** @var \MongoDb $db */
          $db = $this->clients[$connection_string]->selectDB($server['db']);
          foreach ($db->getCollectionNames() as $collection_name) {
            if (substr($collection_name, 0, 16) == $this->testDatabasePrefix) {
              $db->$collection_name->drop();
            }
          }
        }
      }
    }
  }

  public function nextId($sequence_id = 'generic', $existing_id = 0) {
    if ($existing_id) {
      $this->get('sequences')->update(
        array('_id' => $sequence_id, 'seq' => array('$lt' => $existing_id)),
        array('$set' => array('seq' => $existing_id)));
    }
    $result = $this->get('sequences')->findAndModify(
      array('_id' => $sequence_id),
      array('$inc' => array('seq' => 1)),
      NULL,
      array('upsert' => TRUE));
    $seq = $result ? $result['seq'] : 0;
    return $seq + 1;
  }

  public function __sleep() {
    return ['serverInfo', 'collectionInfo'];
  }
}
