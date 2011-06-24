<?php

require_once 'classes/Matcher.class.php';

class NamedEntityMatcher extends Matcher {

  private $possible_plurals;

  function __construct($text, $ner_vocabs) {
    parent::__construct($text, $ner_vocabs);
  }

  public function match() {
    // See if some of the plurals match straight up.
    $this->match_plurals();
    foreach ($this->possible_plurals as &$item) {
      $item = rtrim($item, 's');
    }
    // Get rid of the plurals in the search candidates.
    $this->search_items = array_merge($this->search_items, $this->possible_plurals);
    $this->term_query();
  }

  private function match_plurals() {

    $this->possible_plurals = array_filter($this->search_items, create_function('$str', 'return (substr($str, -1) == "s");'));
    $this->term_query($this->possible_plurals);
    // Get the alrady matched words out of the candidate list.
    foreach ($this->matches as $vocab) {
      foreach ($vocab as $plural_match) {
        $key = array_search($plural_match['word'], $this->search_items);
        if ($key) {
          unset($this->search_items[$key]);
          unset($this->possible_plurals[$key]);
        }
        $key = FALSE;
      }
    }
  }
}