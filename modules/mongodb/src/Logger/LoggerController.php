<?php
/**
 * @file
 * Controller service for the MongoDB Watchdog reports.
 *
 * @license General Public License version 2 or later
 */

namespace Drupal\mongodb\Logger;


use Drupal\Component\Utility\String;
use Drupal\Core\Form\FormBuilderInterface;

class LoggerController {

  /**
   * @var \Drupal\mongodb\Logger\Logger
   */
  protected $logger;

  /**
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  public function __construct(Logger $logger = NULL, FormBuilderInterface $formBuilder) {
    $this->logger = $logger;
    $this->formBuilder = $formBuilder;
  }

  /**
   * Build a MongoDB query based on the selected filters.
   *
   * @return array
   *   An array to build a MongoDB query.
   */
  function buildFilterQuery() {
    if (empty($_SESSION['mongodb_watchdog_overview_filter'])) {
      return array();
    }

    // Build query.
    $types = $_SESSION['mongodb_watchdog_overview_filter']['type'] ? $_SESSION['mongodb_watchdog_overview_filter']['type'] : NULL;
    $severities = $_SESSION['mongodb_watchdog_overview_filter']['severity'] ? $_SESSION['mongodb_watchdog_overview_filter']['severity'] : NULL;

    $find = array();
    if ($types) {
      $find['type'] = array('$in' => $types);
    }
    if ($severities) {
      // MongoDB is picky about types, ensure the severities are all integers.
      $find['severity'] = array('$in' => array_map('intval', $severities));
    }
    return $find;
  }

  /**
   * Formats a log message for display.
   *
   * @param $dblog
   *   An object with at least the message and variables properties
   *
   * @return string
   */
  function formatMessage($dblog) {
    // Legacy messages and user specified text
    if (!isset($dblog['variables'])) {
      return $dblog['message'];
    }
    // Message to translate with injected variables
    return t($dblog['message'], $dblog['variables']);
  }

  /**
   * Initialize the global pager variables for use in a mongodb_watchdog event list.
   */
  function pagerInit($element, $limit, $total) {
    global $pager_page_array, $pager_total, $pager_total_items;

    // Initialize pager, see pager.inc.
    $page = isset($_GET['page']) ? $_GET['page'] : '';
    $pager_page_array = explode(',', $page);
    if (!isset($pager_page_array[$element])) {
      $pager_page_array[$element] = 0;
    }
    $pager_total_items[$element] = $total;
    $pager_total[$element] = ceil($pager_total_items[$element] / $limit);
    $pager_page_array[$element] = max(0, min((int)$pager_page_array[$element], ((int)$pager_total[$element]) - 1));
    return isset($pager_page_array[$element]) ? $pager_page_array[$element] : 0;
  }

  /**
   * usort() helper function to sort top entries returned from a group query.
   *
   * @param array $x
   * @param array $y
   *
   * @return boolean
   */
  function sortTop($x, $y) {
    $cx = $x['count'];
    $cy = $y['count'];
    return $cy - $cx;
  }

  /**
   * Display watchdogs entries in mongodb.
   * @TODO
   *   Use theme function.
   *   Use exposed filter like dblog.
   *
   * @return array
   *   a form array
   */
  function watchdogOverview() {
    $icons = array(
      WATCHDOG_DEBUG     => '',
      WATCHDOG_INFO      => '',
      WATCHDOG_NOTICE    => '',
      WATCHDOG_WARNING   => array('#theme' => 'image', 'path' => 'misc/watchdog-warning.png', 'alt' => t('warning'), 'title' => t('warning')),
      WATCHDOG_ERROR     => array('#theme' => 'image', 'path' => 'misc/watchdog-error.png', 'alt' => t('error'), 'title' => t('error')),
      WATCHDOG_CRITICAL  => array('#theme' => 'image', 'path' => 'misc/watchdog-error.png', 'alt' => t('critical'), 'title' => t('critical')),
      WATCHDOG_ALERT     => array('#theme' => 'image', 'path' => 'misc/watchdog-error.png', 'alt' => t('alert'), 'title' => t('alert')),
      WATCHDOG_EMERGENCY => array('#theme' => 'image', 'path' => 'misc/watchdog-error.png', 'alt' => t('emergency'), 'title' => t('emergency')),
    );

    global $pager_page_array, $pager_total, $pager_total_items, $pager_limits;
    $per_page = 50;
    $page = isset($_GET['page']) ? $_GET['page'] : '';
    $pager_page_array = explode(',', $page);
    $on_page = $pager_page_array[0];

    $cursor = $this->logger->templatesCollection()
      ->find($this->buildFilterQuery())
      ->limit($per_page)
      ->skip($on_page * $per_page)
      ->sort(array('timestamp' => -1));

    $build['mongodb_watchdog_filter_form'] = $this->formBuilder->getForm('Drupal\mongodb\Form\LoggerClearForm');
    $build['mongodb_watchdog_clear_log_form'] = $this->formBuilder->getForm('Drupal\mongodb\Form\LoggerFilterForm');

    $header = array(
      '', // Icon column.
      t('#'),
      array('data' => t('Type')),
      array('data' => t('Date')),
      t('Source'),
      t('Message'),
    );

    $rows = array();
    foreach ($cursor as $id => $value) {
      if ($value['type'] == 'php' && $value['message'] == '%type: %message in %function (line %line of %file).') {
        $collection = $this->logger->eventCollection($value['_id']);
        $result = $collection->find()
          ->sort(array('$natural' => -1))
          ->limit(1)
          ->getNext();
        if ($value) {
          $value['file'] = basename($result['variables']['%file']);
          $value['line'] = $result['variables']['%line'];
          $value['message'] = '%type in %function';
          $value['variables'] = $result['variables'];
        }
      }
      $message = truncate_utf8(strip_tags($this->formatMessage($value)), 56, TRUE, TRUE);
      $rows[$id] = array(
        $icons[$value['severity']],
        isset($value['count']) && $value['count'] > 1 ? $value['count'] : '',
        t($value['type']),
        empty($value['timestamp']) ? '' : format_date($value['timestamp'], 'short'),
        empty($value['file']) ? '' : truncate_utf8(basename($value['file']), 30) . (empty($value['line']) ? '' : ('+' . $value['line'])),
        l($message, "admin/reports/mongodb/$id"),
      );
    }

    $build['mongodb_watchdog_table'] = array(
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#attributes' => array('id' => 'admin-mongodb_watchdog'),
    );

    // Add the pager.
    if ($on_page > 0 || count($rows) >= $per_page) {
      $pager_total_items[0] = $this->logger->templatesCollection()
        ->count($this->buildFilterQuery());
      $pager_total[0] = ceil($pager_total_items[0] / $per_page);
      $pager_page_array[0] = max(0, min((int) $pager_page_array[0], ((int)$pager_total[0]) - 1));
      $pager_limits[0] = $per_page;
      $build['pager'] = array(
        '#theme' => 'pager',
      );
    }

    return $build;
  }

    /**
   * Display watchdogs entry details in MongoDB.
   *
   * @param string $template_id
   */
  public function watchdogEvent($template_id) {
    $event_template = $this->logger->eventLoad($template_id);
    if (empty($event_template)) {
      drupal_set_message($message = t('Template not found, faking it for debug'), 'warning');
      watchdog(__CLASS__, $message, array(), WATCHDOG_DEBUG);
      $event_template = array(
        '_id' => str_repeat('0', 32),
        'type' => 'fake template',
        'severity' => WATCHDOG_DEBUG,
        'function' => '(function unknown)',
        'file' => '(file unknown)',
        'line' => '(line unknown)',
        'count' => 0,
      );
    }

    $severity = watchdog_severity_levels();
    $rows = array(
      array(
        array('data' => t('Type'), 'header' => TRUE),
        t($event_template['type']),
      ),
      array(
        array('data' => t('Severity'), 'header' => TRUE),
        $severity[$event_template['severity']],
      ),
      array(
        array('data' => t('Function'), 'header' => TRUE),
        isset($event_template['function']) ? $event_template['function'] : '',
      ),
      array(
        array('data' => t('File'), 'header' => TRUE),
        isset($event_template['file']) ? $event_template['file'] : '',
      ),
      array(
        array('data' => t('Line'), 'header' => TRUE),
        isset($event_template['line']) ? $event_template['line'] : '',
      ),
      array(
        array('data' => t('Count'), 'header' => TRUE),
        isset($event_template['count']) ? $event_template['count'] : '',
      ),
    );
    $build['reports'] = array(
      '#type' => 'markup',
      '#markup' => l(t('Return to log report'), 'admin/reports/mongodb'),
    );
    $build['mongodb_watchdog_event_table']['header'] = array(
      '#theme' => 'table',
      '#rows' => $rows,
      '#attributes' => array('class' => array('dblog-event')),
    );
    // @todo: the count is unreliable, so just get the actual number of entries.
    //$total = min($dblog['count'], variable_get('mongodb_watchdog_items', 10000));

    $collection = $this->logger->eventCollection($event_template['_id']);

    $total = $collection->count();
    $limit = 20;
    $page_number = $this->pagerInit(0, $limit, $total);
    $result = $collection
      ->find()
      ->skip($page_number * $limit)
      ->limit($limit)
      ->sort(array('$natural' => -1));
    $rows = array();
    $header = array(
      array('data' => t('Date'), 'header' => TRUE),
      array('data' => t('User'), 'header' => TRUE),
      array('data' => t('Location'), 'header' => TRUE),
      array('data' => t('Referrer'), 'header' => TRUE),
      array('data' => t('Hostname'), 'header' => TRUE),
      array('data' => t('Message'), 'header' => TRUE),
      array('data' => t('Operations'), 'header' => TRUE),
    );
    foreach ($result as $event) {
      if (isset($event['wd-user'])) {
        $account = $event['wd-user'];
        unset($event['wd-user']);
        $ip = $event_template['ip'];
        $request_uri = $event_template['request_uri'];
        $referer = $event_template['referer'];
        $link = $event_template['link'];
        $event_template['variables'] = $event;
      }
      else {
        $account = $event['user'];
        $ip = $event['ip'];
        $request_uri = $event['request_uri'];
        $referer = $event['referer'];
        $link = $event['link'];
        $event_template['variables'] = $event['variables'];
      }
      $rows[] = array(
        format_date($event['timestamp'], 'short'),
        l($account['name'], 'user/' . $account['uid']),
        $request_uri ? l(truncate_utf8(basename(($request_uri)), 20), $request_uri) : '',
        $referer ? l(truncate_utf8(basename(($referer)), 20), $referer) : '',
        String::checkPlain($ip),
        $this->formatMessage($event_template),
        $link,
      );
    }
    $build['mongodb_watchdog_event_table']['messages'] = array(
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
    );
    if ($total > $limit) {
      $build['mongodb_watchdog_event_table']['pager'] = array(
        '#theme' => 'pager',
      );
    }
    return $build;
  }

  /**
   * Page callback for "admin/reports/[access-denied|page-not-found]".
   *
   * @param string $type
   *
   * @return array
   */
  function watchdogTop($type) {
    $ret = array();
    $type_param = array('%type' => $type);
    $limit = 50;

    // Safety net
    $types = array(
      'page not found',
      'access denied',
    );
    if (!in_array($type, $types)) {
      drupal_set_message(t('Unknown top report type: %type', $type_param), 'error');
      watchdog('mongodb_watchdog', 'Unknown top report type: %type', $type_param, WATCHDOG_WARNING);
      $ret = '';
      return $ret;
    }

    // Find _id for the error type.
    $templates = $this->logger->templatesCollection();
    $criteria = array(
      'type' => $type,
    );
    $fields = array(
      '_id',
    );
    $template = $templates->findOne($criteria, $fields);

    // findOne() will return NULL if no row is found
    if (empty($template)) {
      $ret['empty'] = array(
        '#markup' => t('No "%type" message found', $type_param),
        '#prefix' => '<div class="mongodb-watchdog-message">',
        '#suffix' => '</div>',
      );
      $ret = drupal_render($ret);
      return $ret;
    }

    // Find occurrences of error type.
    $event_collection = $this->logger->eventCollection($template['_id']);

    $key = 'variables.@param';
    $keys = array(
      $key => 1,
    );
    $initial = array(
      'count' => 0,
    );
    $reduce = new \MongoCode(file_get_contents(__DIR__ . '/top_reductor.js'));

    $counts = $event_collection->group($keys, $initial, $reduce);

    if (!$counts['ok']) {
      drupal_set_message(t('No "%type" occurrence found', $type_param), 'error');
      return '';
    }
    $counts = $counts['retval'];
    usort($counts, array($this, 'sortTop'));
    $counts = array_slice($counts, 0, $limit);

    $header = array(
      t('#'),
      t('Paths'),
    );
    $rows = array();
    foreach ($counts as $count) {
      $rows[] = array(
        $count['count'],
        $count[$key],
      );
    }

    $ret = array(
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
    );
    return $ret;
  }
}
