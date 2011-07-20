<?php
define('__ROOT__', dirname(__FILE__) . '/');

require_once __ROOT__ . 'classes/TaggedText.class.php';

class Tagger {

  private static $instance;

  private $conf_settings;

  private $configuration;


  private function __construct($configuration = array())  {
    set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));
    define('TAGGER_DIR', dirname(__FILE__));
    include 'defaults.php';
    $tagger_conf = array_merge($tagger_conf, $configuration);
    if (!isset($configuration) || empty($configuration)) {
      include 'conf.php';
    }
    $this->configuration = $tagger_conf;

  }

  public static function getTagger($configuration = array()) {
    if (!isset(self::$instance)) {
        $c = __CLASS__;
        self::$instance = new $c($configuration);
    }
    return self::$instance;
  }

  public function getConfiguration($setting = NULL) {
    if ($setting === NULL) {
      return $this->configuration;
    }
    else if (isset($this->configuration[$setting])) {
      return $this->configuration[$setting];
    }
    else {
      return FALSE;
    }
  }

  // Prevent users to clone the instance
  public function __clone() {
    trigger_error('Clone is not allowed.', E_USER_ERROR);
  }



  public function tagText($text, $rate_html = TRUE, $return_marked_text = FALSE, $rating = array(), $ner_vocab_ids = array(), $disambiguate = FALSE, $return_uris = FALSE, $return_unmatched = FALSE, $nl2br = FALSE) {
    if (empty($ner_vocab_ids)) {
      $ner_vocab_names = $this->getConfiguration('ner_vocab_names');
      $ner_vocab_ids = array_keys($ner_vocab_names);
      if (!isset($ner_vocab_ids) || empty($ner_vocab_ids)) {
        throw new ErrorException('Missing vocab definition in configuration.');
      }
    }

    if (empty($rating)) {
      $rating['frequency'] = $this->getConfiguration('frequency_rating');
      $rating['positional'] = $this->getConfiguration('positional_rating');
      $rating['HTML'] = $this->getConfiguration('HTML_rating');

      $rating['positional_minimum'] = $this->getConfiguration('positional_minimum_rating');
      $rating['positional_critical_token_count'] = $this->getConfiguration('positional_critical_token_count_rating');


      if ($key = array_search(FALSE, $rating, TRUE)) {
        throw new ErrorException('Missing ' . $key . '_rating definition in configuration.');
      }
    }



    $tagged_text = new TaggedText($text, $ner_vocab_ids, $rate_html, $return_marked_text, $rating);
    $tagged_text->process();
    return $tagged_text;
  }
}


