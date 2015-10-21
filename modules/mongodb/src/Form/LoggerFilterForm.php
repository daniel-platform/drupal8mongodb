<?php

/**
 * @file
 * Contains \Drupal\mongodb\Form\DblogFilterForm.
 */

namespace Drupal\mongodb\Form;


use Drupal\Core\Form\FormBase;
use Drupal\mongodb\Logger\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the MongoDB logging filter form.
 */
class LoggerFilterForm extends FormBase {

  /**
   * @var \Drupal\mongodb\Logger\Logger
   */
  protected $logger;

  /**
   * Constructs a new LoggerFilterForm.
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
    $filters = $this->getFilters();

    $form['filters'] = array(
      '#type' => 'details',
      '#title' => t('Filter log messages'),
      '#open' => !empty($_SESSION['mongodb_watchdog_overview_filter']),
      '#weight' => 1,
    );

    foreach ($filters as $key => $filter) {
      $form['filters']['status'][$key] = array(
        '#title' => $filter['title'],
        '#type' => 'select',
        '#multiple' => TRUE,
        '#size' => 8,
        '#options' => $filter['options'],
      );
      if (!empty($_SESSION['mongodb_watchdog_overview_filter'][$key])) {
        $form['filters']['status'][$key]['#default_value'] = $_SESSION['mongodb_watchdog_overview_filter'][$key];
      }
    }

    $form['filters']['actions'] = array(
      '#type' => 'actions',
      '#attributes' => array('class' => array('container-inline')),
    );
    $form['filters']['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
    );
    if (!empty($_SESSION['mongodb_watchdog_overview_filter'])) {
      $form['filters']['actions']['reset'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Reset'),
        '#limit_validation_errors' => array(),
        '#submit' => array(array($this, 'resetForm')),
      );
    }

    $form['#weight'] = -2;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, array &$form_state) {
    if (empty($form_state['values']['type']) && empty($form_state['values']['severity'])) {
      $this->setFormError('type', $form_state, $this->t('You must select something to filter by.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    $filters = $this->getFilters();
    foreach ($filters as $name => $filter) {
      if (isset($form_state['values'][$name])) {
        $_SESSION['mongodb_watchdog_overview_filter'][$name] = $form_state['values'][$name];
      }
    }
  }

  /**
   * Gets all available filter types.
   *
   * @return array
   *   An array of message type names.
   */
  function getMessageTypes() {
    // As of version 1.0.1, the PHP driver doesn't expose the 'distinct' command.
    $collection = $this->logger->templatesCollection();
    $result = $collection->db->command(array(
      'distinct' => $collection->getName(),
      'key' => 'type',
    ));
    return $result['values'];
  }

  /*
   * List mongodb_watchdog administration filters that can be applied.
   *
   * @return array
   *   A form array
   */
  function getFilters() {
    $filters = array();

    foreach ($this->getMessageTypes() as $type) {
      $types[$type] = $type;
    }

    if (!empty($types)) {
      $filters['type'] = array(
        'title' => t('Type'),
        'options' => $types,
      );
    }

    $filters['severity'] = array(
      'title' => t('Severity'),
      'options' => watchdog_severity_levels(),
    );

    return $filters;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mongodb_watchdog_filter_form';
  }

  /**
   * Resets the filter form.
   */
  public function resetForm(array &$form, array &$form_state) {
    $_SESSION['mongodb_watchdog_overview_filter'] = array();
  }

}
