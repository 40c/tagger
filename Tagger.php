<?php

class Tagger {

  private static $instance;

  private $conf_settings;
  
  private $configuration;
    
  private function __construct($configuration = array())  {
    set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));
    define('TAGGER_DIR', dirname(__FILE__));
    include 'defaults.php';
    $tagger_conf = array_merge($tagger_conf, $configuration);
    if (!isset($configuration)) {
      include 'conf.php';
    }
    include 'classes/TaggerController.inc.php';
    $this->configuration = $tagger_conf;
  }

  public static function getTagger($configuration = array()) {
    if (!isset(self::$instance)) {
        $c = __CLASS__;
        self::$instance = new $c($configuration);
    }
    return self::$instance;
  }

  public function getConfiguration($setting) {
    if (isset($this->configuration[$setting])) {
      return $this->configuration[$setting];
    }
    return $this->configuration;
  }

  // Prevent users to clone the instance
  public function __clone() {
    trigger_error('Clone is not allowed.', E_USER_ERROR);
  }
  


  public function tagText($text, $ner, $disambiguate = FALSE, $return_uris = FALSE, $return_unmatched = FALSE, $use_markup = FALSE, $nl2br = FALSE) {
    $controller = new TaggerController($text, $ner, $disambiguate, $return_uris, $return_unmatched, $use_markup, $nl2br);
    $controller->process();
    return $controller->getProcessedResponse();
  }
}

