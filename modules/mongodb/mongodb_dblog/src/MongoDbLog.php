<?php

/**
 * @file
 * Contains \Drupal\mongodb\MongoDbLog.
 */

namespace Drupal\mongodb_dblog;

use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\mongodb\MongoCollectionFactory;
use Psr\Log\LoggerInterface;

class MongoDbLog implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * MongoDB collection factory.
   *
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $mongo;

  /**
   * The message's placeholders parser.
   *
   * @var \Drupal\Core\Logger\LogMessageParserInterface
   */
  protected $parser;

  /**
   * Constructs a DbLog object.
   *
   * @param \Drupal\mongodb\MongoCollectionFactory $mongo
   *   The mongo collection factory.
   * @param \Drupal\Core\Logger\LogMessageParserInterface $parser
   *   The parser to use when extracting message variables.
   */
  public function __construct(MongoCollectionFactory $mongo, LogMessageParserInterface $parser) {
    $this->mongo = $mongo;
    $this->parser = $parser;
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = array()) {
    // Remove any backtraces since they may contain an unserializable variable.
    unset($context['backtrace']);

    // Convert PSR3-style messages to String::format() style, so they can be
    // translated too in runtime.
    $message_placeholders = $this->parser->parseMessagePlaceholders($message, $context);

    $this->mongo->get('watchdog')
      ->insert(array(
        'uid' => $context['uid'],
        'type' => substr($context['channel'], 0, 64),
        'message' => $message,
        'variables' => serialize($message_placeholders),
        'severity' => $level,
        'link' => substr($context['link'], 0, 255),
        'location' => $context['request_uri'],
        'referer' => $context['referer'],
        'hostname' => substr($context['ip'], 0, 128),
        'timestamp' => $context['timestamp'],
      ));
  }

}
