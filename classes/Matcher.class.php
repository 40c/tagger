<?php
  include 'db/TaggerQueryManager.class.php';

abstract class Matcher {
  protected $matches;
  protected $numresults = 0;
  protected $search_items;
  protected $vocabularies;
  protected $nonmatches;

  function __construct($potential_entities, $vocab_array) {
    $search_items = array();
    foreach ($potential_entities as $entity) {
      $search_items[] = implode(' ', $entity);
    }
    $this->search_items = array_unique($search_items);
    $this->matches = array();
    $this->nonmatches = array();
    $this->vocabularies = implode(', ', $vocab_array);
  }

  protected function term_query() {
    $tagger_instance = Tagger::getTagger();
    $vocab_names = $tagger_instance->getConfiguration('vocab_names');
    if (!empty($this->vocabularies) && !empty($this->search_items)) {
      $imploded_words = implode("','", $this->search_items);
      $unmatched = array_flip($this->search_items);
      $result = TaggerQueryManager::query("SELECT COUNT(tid) AS count, tid, name, vid FROM term_data WHERE vid IN($this->vocabularies) AND (name IN('$imploded_words') OR tid IN(SELECT tid FROM term_synonym WHERE name IN('$imploded_words'))) GROUP BY BINARY name");
      while ($row = mysqli_fetch_assoc($result)) {
        $matchword = '';
        if (array_key_exists($row['name'], $unmatched)) {
          unset($unmatched[$row['name']]);
          $matchword = $row['name'];
        }
        if (isset($row['synonym']) && array_key_exists($row['synonym'], $unmatched)) {
          unset($unmatched[$row['synonym']]);
          $matchword = $row['synonym'];
        }
        $this->matches[$row['vid']][$row['tid']] = array('word' => $row['name'], 'match' => $matchword, 'hits' => $row['count']);
      }
      $this->nonmatches = array_flip($unmatched);
    }
  }

  public function get_matches() {
    return $this->matches;
  }
  public function get_nonmatches() {
    return $this->nonmatches;
  }
  abstract protected function match();
}