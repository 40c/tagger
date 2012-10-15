<?php
/**
 * @file
 * Contains Tagger.
 *
 * The base of the Tagger library.
 */

/**
 * The full path of the root directory of Tagger.
 */
define('__ROOT__', dirname(__FILE__) . '/');

/**
 * The Tagger version number.
 */
define('TAGGER_VERSION', 4);

mb_internal_encoding('UTF-8');

require_once __ROOT__ . 'classes/TaggerHelpers.class.php';
require_once __ROOT__ . 'classes/TagProcessor.class.php';
require_once __ROOT__ . 'logger/TaggerLogManager.class.php';


/**
 * The Tagger root class
 *
 * A singleton that can be accessed statically
 *
 */
class Tagger {


  /**
   * The singleton instance.
   */
  private static $instance;

  /**
   * The Tagger configuration.
   */
  private static $configuration;

  /**
   * Variable for holding the list of initwords.
   */
  public static $initwords;

  /**
   * Variable for holding the list of prefix/infix words.
   */
  public static $prefix_infix;

  /**
   * Variable for holding the list of stopwords.
   */
  public static $stopwords;

  /**
   * When overriding the configuration the settings in this array will be fully
   * overriden (not appended to). Defaults to array('vocab_ids').
   */
  private static $override = array('vocab_ids');

  /**
   * Constructs the Tagger object.
   *
   * @param array $configuration
   *   Configuration for the current Tagger session.
   * @param string $file
   *   Configuration file to be loaded. Defaults to 'conf.php'.
   */
  private function __construct($configuration = array(), $file = 'conf.php')  {
    set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));
    define('TAGGER_DIR', dirname(__FILE__));
    include 'defaults.php';
    $tagger_conf = array_merge($tagger_conf, $configuration);
    if (!isset($configuration) || empty($configuration)) {
      if(file_exists(__ROOT__ . $file)) {
        include $file;
      }
      else {
        throw new Exception("Configuration file '$file' not found.", 1);
      }
    }
    self::$configuration = $tagger_conf;


    $wordlists = array('initwords', 'prefix_infix', 'stopwords');
    foreach ($wordlists AS $wordlist) {
      if (self::$$wordlist == NULL) {
        $path = realpath(__ROOT__ .'resources/'. $wordlist .'/'. $wordlist .'_'.
          self::$configuration['language'] .'.txt');
        self::$$wordlist = array_flip(file($path, FILE_IGNORE_NEW_LINES));
      }
    }

  }

  /**
   * Returns Tagger version number.
   */
  public static function getTaggerVersion() {
    return TAGGER_VERSION;
  }

  /**
   * Returns vocabulary ids.
   */
  public function getVocabularyIds() {
    $sql = sprintf("SELECT vid FROM tagger_lookup GROUP BY vid");
    $result = TaggerQueryManager::query($sql);
    $ids = array();
    while ($row = TaggerQueryManager::fetch($result)) {
      $ids[$row['vid']] = $row['vid'];
    }
    return $ids;
  }

  /**
   * Returns singleton Tagger instance.
   */
  public static function getTagger($configuration = array(), $file = 'conf.php') {
    if (!isset(self::$instance)) {
        $c = __CLASS__;
        self::$instance = new $c($configuration, $file);
    }
    return self::$instance;
  }

  /**
   * Returns either full configuration or a single setting.
   *
   * If called with no arguments this function returns the full configuration
   * array.
   * If called with arguments, each argument is a key in the configuration array
   * i.e. @code getConfiguration('keyword', 'vocab_ids') == $configuration['keyword']['vocab_ids']; @endcode
   */
  public static function getConfiguration() {
    self::getTagger();

    $arg_count = func_num_args();
    if ($arg_count = 0) {
      return self::$configuration;
    }
    else {
      $opt = self::$configuration;
      $setting_str = '$configuration';
      foreach(func_get_args() as $arg) {
        $setting_str .= "['$arg']";
        if (isset($opt[$arg])) {
          $opt = $opt[$arg];
        } else {
          throw new ErrorException('Setting ' . $setting_str . ' not found in configuration.');
        }
      }
      return $opt;
    }
  }

  /**
   * Sets either full configuration or a single setting.
   *
   * If called with array arguments this merges the current configuration
   * with the arguments.
   * If called with non-array arguments the first argument is the new value of
   * the setting. Each following argument is a key in the configuration array.
   * i.e. @code setConfiguration(array(17), 'keyword', 'vocab_ids'); @endcode
   * is equivalent to
   *      @code $configuration['keyword']['vocab_ids'] = array(17); @endcode
   */
  public static function setConfiguration() {
    $arg_count = func_num_args();
    $args = func_get_args();

    // if all arguments are arrays - merge them
    if ( !in_array(FALSE, array_map('is_array', $args), TRUE) ) {
      self::$configuration = call_user_func_array(
        array('TaggerHelpers', 'arrayMergeRecursiveOverride'),
        array_merge(array(self::$override, self::$configuration), $args)
      );
      return self::$configuration;
    }

    // if all arguments are strings - set the option specified
    if ( !in_array(FALSE, array_map('is_string', $args), TRUE) ) {
      if ($arg_count < 2) {
        throw new ErrorException('Need at least two arguments.');
      }

      $opt =& self::$configuration;
      $l = array_slice(func_get_args(), 1);
      $setting_str = '$configuration';
      foreach($l as $arg) {
        $setting_str .= "['$arg']";
        if (is_array($opt) && isset($opt[$arg])) {
          $opt =& $opt[$arg];
        } else {
          throw new ErrorException('Setting ' . $setting_str . ' not found in configuration.');
        }
      }
      $opt = func_get_arg(0);
      return $opt;
    }
  }

  // Prevent users to clone the instance
  public function __clone() {
    trigger_error('Clone is not allowed.', E_USER_ERROR);
  }

  /**
   * This is the main function to call, when you use Tagger.
   *
   * @param $text
   *   The text you want to tag.
   * @param array $options
   *   An associative array of additional options, with the following elements:
   *   - 'ner_vocab_ids': An numeric array vocabularies you want to use for
   *     NER (named entity recognition). Keys are vocabulary ids. Values are
   *     vocabulary names.
   *   - 'keyword_vocab_ids': An numeric array vocabularies you want to use for
   *     Keyword Extraction´. Keys are vocabulary ids. Values are vocabulary names.
   *   - 'rate_html': Boolean indication wheter html-tags should be used to rate
   *     relevancy.
   *   - 'return_marked_text': Boolean, indicates whether Tagger should return
   *     text with markup.
   *   - 'rating': An array TODO: explain array
   *   - 'disambiguate': Boolean indicating whether Tagger should try to disambiguate
   *     ambigous tags.
   *   - 'return_uris': Boolean indicating wheter Tagger should return URI's for
   *     each tag
   *   - 'log_unmatched': Boolean indicating whether unmatched potential
   *     NER candidates should be logged
   *   - 'nl2br': Boolean indicating whether newlines should be convertet to br-tags
   *
   * @return
   *   TagProcessor object
   */

  public function tagText($text, $options = array()) {

    $default = self::$configuration;
    $filtered_conf = array();

    //These configuration options can be overriden when this function is called
    $conf = array(
      'named_entity',
      'keyword',
      'return_marked_text',
      'linked_data',
    );
    foreach ($conf as $key) {
      if (isset($options[$key])) {
        $filtered_conf[$key] = $options[$key];
      }
    }
    // let some $options override $configuration temporarily
    self::setConfiguration(self::$configuration, $filtered_conf);

    $ner      = Tagger::getConfiguration('named_entity', 'vocab_ids');
    $keyword  = Tagger::getConfiguration('keyword', 'vocab_ids');
    if (empty($ner) && empty($keyword)) {
      throw new ErrorException('Missing vocab definition in configuration.');
    }


    $tag_processor = new TagProcessor($text);
    $tag_processor->process();
    self::$configuration = $default;

    return $tag_processor;
  }

  /**
   * Log to the internal Tagger log
   *
   * @param string $message
   *   The text to be logged
   * @param string $level
   *   The logging level of the message ('Verbose', 'Warning', 'Standard')
   *   Defaults to 'Standard'.
   */
  public function log($message, $level = 'Standard') {
    $level = array_search($level, TaggerLogManager::$LOG_TYPE);
    if ($level === FALSE) {
      $level = TaggerLogManager::STANDARD;
    }
    TaggerLogManager::logMsg($message, $level);
  }

}

