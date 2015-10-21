<?php

/**
 * @file
 * Contains \Drupal\mongodb\Logger\LoggerClearForm.php
 */

namespace Drupal\mongodb\Form;


use Drupal\Core\Form\FormBase;
use Drupal\mongodb\Logger\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the form that clears out the log.
 */
class LoggerClearForm extends FormBase {

  /**
   * @var \Drupal\mongodb\Logger\Logger
   */
  protected $logger;

  /**
   * Constructs a new LoggerClearLogForm.
   *
   * @param \Drupal\mongodb\Logger\Logger $logger
   *   The logger service..
   */
  public function __construct(Logger $logger) {
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    /** @var \Drupal\mongodb\Logger\Logger $logger */
    $logger = $container->get('mongo.logger');
    return new static($logger);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state) {
    $form['mongodb_watchdog_clear'] = array(
      '#type' => 'details',
      '#title' => t('Clear log messages'),
      '#description' => $this->t('This will permanently remove the log messages from the database.'),
    );

    $form['mongodb_watchdog_clear']['clear'] = array(
      '#type' => 'submit',
      '#value' => t('Clear log messages'),
    );

    $form['#weight'] = -1;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    $logger = $this->logger;
    try {
      // Drop the watchdog collection.
      $this->logger->templatesCollection()->drop();

      // Recreate the indexes.
      $this->logger->ensureIndexes();

      // Drop the event collections.
      foreach ($logger->templatesCollection()->db->listCollections() as $event_collection) {
        if (preg_match($logger::EVENT_COLLECTIONS_PATTERN, $event_collection->getName())) {
          $event_collection->drop();
        }
      }

      drupal_set_message(t('MongoDB log cleared.'));
    }
    catch (Exception $e) {
      drupal_set_message(t('An error occured while clearing the MongoDB log.'), 'error');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mongodb_watchdog_clear_form';
  }
}
