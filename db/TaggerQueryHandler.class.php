<?php

class TaggerQueryHandler {

  private static $link = NULL;
  private static $instance = NULL;
  private function __construct() {
    $tagger_instance = Tagger::getTagger();
    $db_settings = $tagger_instance->getConfiguration('db');

    try {
      if($db_settings['type'] != 'sqlite') {
        // Anything but SQLite
        $db_settings['dsn'] = $db_settings['type'].':dbname='.$db_settings['name'].';host='.$db_settings['server'];
        $this->link = new PDO($db_settings['dsn'], $db_settings['username'], $db_settings['password'],
                              array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''));
      } else {
        // SQLite only
        $db_settings['dsn'] = $db_settings['type'] .':'. $db_settings['path'];
        $this->link = new PDO($db_settings['dsn'], '', '',
                              array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''));
      }
    } catch (PDOException $e) {
      die('Could not connect: ' . $e->getMessage());
    }

    // If you are on an older version of PHP and have trouble with the function
    // call above here, try this instead: mysql_query("SET NAMES 'utf8'");
  }

  public function __destruct() {
    $this->link = NULL;
  }


  public function query($sql, $args) {
    if (!isset(self::$instance)) {
      $c = __CLASS__;
      self::$instance = new $c;
    }
    $result = self::$instance->link->query(sprintf($sql, $args));
    if($result) {
      return $result;
    } else {
      $error_msg = self::$instance->link->errorInfo();
      die('Database error: '. $error_msg[2] . "\n" . 'Query: ' . $sql);
    }
  }
}
