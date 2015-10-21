<?php

namespace Drupal\mongodb_locale;

use Drupal\locale\SourceString;
use Drupal\locale\StringInterface;
use Drupal\locale\StringStorageException;
use Drupal\locale\StringStorageInterface;
use Drupal\locale\TranslationString;
use Drupal\mongodb\MongoCollectionFactory;

class StringMongoDBStorage implements StringStorageInterface {

  /**
   * @var \Drupal\mongodb\MongoCollectionFactory
   */
  protected $mongo;

  public function __construct(MongoCollectionFactory $mongo) {
    $this->mongo = $mongo;
  }

  /**
   * Loads multiple source string objects.
   *
   * @param array $conditions
   *   (optional) Array with conditions that will be used to filter the strings
   *   returned and may include any of the following elements:
   *   - Any simple field value indexed by field name.
   *   - 'translated', TRUE to get only translated strings or FALSE to get only
   *     untranslated strings. If not set it returns both translated and
   *     untranslated strings that fit the other conditions.
   *   Defaults to no conditions which means that it will load all strings.
   * @param array $options
   *   (optional) An associative array of additional options. It may contain
   *   any of the following optional keys:
   *   - 'filters': Array of string filters indexed by field name.
   *   - 'pager limit': Use pager and set this limit value.
   *
   * @return array
   *   Array of \Drupal\locale\StringInterface objects matching the conditions.
   */
  public function getStrings(array $conditions = array(), array $options = array()) {
    $strings = array();
    foreach ($this->mongo->get('locale')->find($this->getFilterFindArray($conditions, $options)) as $record) {
      $string = new SourceString($record);
      $string->setStorage($this);
      $strings[] = $string;
    }
    return $strings;
  }

  /**
   * Creates a MongoDB find array based on conditions and filters.
   *
   * @param $conditions
   * @param $options
   * @return array
   */
  protected function getFilterFindArray($conditions, $options) {
    $find = $this->getFindArray($conditions);
    if (isset($options['filters'])) {
      if (isset($options['filters']['source'])) {
        $find['source'] = $this->getMongoRegex($options['filters']['source']);
      }
      if (isset($options['filters']['translation'])) {
        $find['translations'] = array(
          // Language must be set otherwise it makes no sense to search for a translation.
          'language' => $conditions['language'],
          'translation' => $this->getMongoRegex($options['filters']['translation']),
          // We need something so that we always can run a full match.
          'customized' => isset($conditions['customized']) ? (bool) $conditions['customized'] : array('$exists' => TRUE),
        );
        unset($conditions['customized']);
        unset($conditions['language']);
      }
    }
    if (isset($conditions['customized'])) {
      $find['translations']['$elemMatch']['customized'] = $conditions['customized'];
    }
    if (isset($conditions['language'])) {
      $find['translations']['$elemMatch']['language'] = $conditions['language'];
    }
    if (isset($conditions['translated']) && empty($find['translations'])) {
      $find['translations']['$exists'] = $conditions['translated'];
    }
    return $find;
  }

  /**
   * Creates a MongoDB regex
   *
   * @param $value
   * @return \MongoRegex
   */
  protected function getMongoRegex($value) {
    return new \MongoRegex('/' . preg_quote($value, '/') . '/');
  }

  /**
   * Loads multiple string translation objects.
   *
   * @param array $conditions
   *   (optional) Array with conditions that will be used to filter the strings
   *   returned and may include all of the conditions defined by getStrings().
   * @param array $options
   *   (optional) An associative array of additional options. It may contain
   *   any of the options defined by getStrings().
   *
   * @return array
   *   Array of \Drupal\locale\StringInterface objects matching the conditions.
   *
   * @see \Drupal\locale\StringStorageInterface::getStrings()
   */
  public function getTranslations(array $conditions = array(), array $options = array()) {
    // TODO: Implement getTranslations() method.
  }

  /**
   * Loads string location information.
   *
   * @param array $conditions
   *   (optional) Array with conditions to filter the locations that may be any
   *   of the follwing elements:
   *   - 'sid', The tring identifier.
   *   - 'type', The location type.
   *   - 'name', The location name.
   *
   * @return array
   *   Array of \Drupal\locale\StringInterface objects matching the conditions.
   *
   * @see \Drupal\locale\StringStorageInterface::getStrings()
   */
  public function getLocations(array $conditions = array()) {
    // TODO: Implement getLocations() method.
  }

  /**
   * Loads a string source object, fast query.
   *
   * These 'fast query' methods are the ones in the critical path and their
   * implementation must be optimized for speed, as they may run many times
   * in a single page request.
   *
   * @param array $conditions
   *   (optional) Array with conditions that will be used to filter the strings
   *   returned and may include all of the conditions defined by getStrings().
   *
   * @return \Drupal\locale\SourceString|null
   *   Minimal TranslationString object if found, NULL otherwise.
   */
  public function findString(array $conditions) {
    $find = $this->getFindArray($conditions);
    if ($values = $this->mongo->get('locale')->findOne($find, array('source', 'context', 'version'))) {
      $values['lid'] = (string) $values['_id'];
      unset($values['_id']);
      return (new SourceString($values))->setStorage($this);
    }
  }

  /**
   * Loads a string translation object, fast query.
   *
   * This function must only be used when actually translating strings as it
   * will have the effect of updating the string version. For other purposes
   * the getTranslations() method should be used instead.
   *
   * @param array $conditions
   *   (optional) Array with conditions that will be used to filter the strings
   *   returned and may include all of the conditions defined by getStrings().
   *
   * @return \Drupal\locale\TranslationString|null
   *   Minimal TranslationString object if found, NULL otherwise.
   */
  public function findTranslation(array $conditions) {
    $language = $conditions['language'];
    $find = array();
    $find['translations.language'] = $language;
    if (isset($conditions['lid'])) {
      $find += $this->getIdCriteria($conditions['lid']);
    }
    if (isset($conditions['context'])) {
      $find['context'] = $conditions['context'];
    }
    $values = $this->mongo->get('locale')->findOne($find, array(
      'source' => TRUE,
      'context' => TRUE,
      'version' => TRUE,
      'translations.$' => TRUE,
    ));
    if ($values) {
      $values['lid'] = (string) $values['_id'];
      // $values['translations'][0] contains the right translation courtesy of
      // the translations.$ projection operator.
      $values['translation'] = $values['translations'][0]['translation'];
      $values['customized'] = $values['translations'][0]['customized'];
      unset($values['translations'], $values['_id']);
      $string = new TranslationString($values);
      $this->checkVersion($string, \Drupal::VERSION);
      return $string->setStorage($this);
    }
  }

  /**
   * Checks whether the string version matches a given version, fix it if not.
   *
   * @param \Drupal\locale\StringInterface $string
   *   The string object.
   * @param string $version
   *   Drupal version to check against.
   */
  protected function checkVersion($string, $version) {
    if ($string->getId() && $string->getVersion() != $version) {
      $string->setVersion($version);
      $update['$set']['version'] = $version;
      $this->mongo->get('locale')->update($this->getIdCriteria($string->getId()), $update);
    }
  }

  protected function getIdCriteria($id) {
    return array('_id' => new \MongoId($id));
  }

  /**
   * Save string object to storage.
   *
   * @param \Drupal\locale\StringInterface $string
   *   The string object.
   *
   * @return \Drupal\locale\StringStorageInterface
   *   The called object.
   *
   * @throws \Drupal\locale\StringStorageException
   *   In case of failures, an exception is thrown.
   */
  public function save($string) {
    $collection = $this->mongo->get('locale');
    if ($string->isSource()) {
      $string->setValues(array('context' => '', 'version' => 'none'), FALSE);
      $values = $string->getValues(array('source', 'context', 'version'));
      $criteria = array();
      if ($id = $string->getId()) {
        $criteria = $this->getIdCriteria($id);
        $values += $criteria;
      }
      $collection->update($criteria, $values, array('upsert' => TRUE));
    }
    elseif ($string->isTranslation()) {
      $string->setValues(array('customized' => FALSE), FALSE);
      $values = $string->getValues(array('language', 'translation', 'customized'));
      $values['customized'] = (bool) $values['customized'];
      $collection = $this->mongo->get('locale');
      $criteria = $this->getIdCriteria($string->getId());
      // Try updating an existing translation.
      $criteria['translations.language'] = $values['language'];
      $result = $collection->update($criteria, array('$set' => array('translations.$' => $values)));
      // If it didn't exist, try to add it.
      if (!$result['updatedExisting']) {
        // Do not add this translation again if it got added since the
        // previous query (in a race condition).
        $criteria['translations.language'] = array('$ne' => $values['language']);
        $collection->update($criteria, array('$push' => array('translations' => $values)));
      }
    }
    else {
      throw new StringStorageException(format_string('The string cannot be saved: @string', array(
          '@string' => $string->getString()
      )));
    }
    return $this;
  }

  /**
   * Delete string from storage.
   *
   * @param \Drupal\locale\StringInterface $string
   *   The string object.
   *
   * @return \Drupal\locale\StringStorageInterface
   *   The called object.
   *
   * @throws \Drupal\locale\StringStorageException
   *   In case of failures, an exception is thrown.
   */
  public function delete($string) {
    $this->mongo->get('locale')->remove($this->getIdCriteria($string->getId()));
  }

  /**
   * Deletes source strings and translations using conditions.
   *
   * @param array $conditions
   *   Array with simple field conditions for source strings.
   */
  public function deleteStrings($conditions) {
    // TODO: Implement deleteStrings() method.
  }

  /**
   * Deletes translations using conditions.
   *
   * @param array $conditions
   *   Array with simple field conditions for string translations.
   */
  public function deleteTranslations($conditions) {
    // TODO: Implement deleteTranslations() method.
  }

  /**
   * Counts source strings.
   *
   * @return int
   *   The number of source strings contained in the storage.
   */
  public function countStrings() {
    // TODO: Implement countStrings() method.
  }

  /**
   * Counts translations.
   *
   * @return array
   *   The number of translations for each language indexed by language code.
   */
  public function countTranslations() {
    // TODO: Implement countTranslations() method.
  }

  /**
   * Creates a source string object bound to this storage but not saved.
   *
   * @param array $values
   *   (optional) Array with initial values. Defaults to empty array.
   *
   * @return \Drupal\locale\SourceString
   *   New source string object.
   */
  public function createString($values = array()) {
    // TODO: Implement createString() method.
  }

  /**
   * Creates a string translation object bound to this storage but not saved.
   *
   * @param array $values
   *   (optional) Array with initial values. Defaults to empty array.
   *
   * @return \Drupal\locale\TranslationString
   *   New string translation object.
   */
  public function createTranslation($values = array()) {
    // TODO: Implement createTranslation() method.
  }

  /**
   * @param array $conditions
   * @return array
   */
  protected function getFindArray(array $conditions) {
    $find = array();
    if (isset($conditions['lid'])) {
      $find += $this->getIdCriteria($conditions['lid']);
    }
    if (isset($conditions['context'])) {
      $find['context'] = $conditions['context'];
    }
    if (isset($conditions['type'])) {
      $find['location.type'] = $conditions['type'];
      return $find;
    }
    return $find;
  }
}
