<?php

class TaggerQueryManager {

  private function __construct() {

  }

  public static function query($sql, $args = array()) {
    
    $tagger_instance = Tagger::getTagger();
    $dbhandler = $tagger_instance->getConfiguration('dbhandler');
    if (!isset($dbhandler) || (isset($dbhandler) && $dbhandler == 'Default')) {
      include_once 'TaggerQueryHandler.class.php';
      return TaggerQueryHandler::query($sql, $args);
    } else {
      if (class_exists($dbhandler.'QueryHandler')) {
        return call_user_func($dbhandler.'QueryHandler::query', $sql, $args);
      }
    }
  }
}