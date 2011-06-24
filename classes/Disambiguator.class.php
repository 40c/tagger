<?php

class Disambiguator {

  private $tags;

  public function __construct($tags) {
    $this->tags = $tags;
    $this->tag_ids = array();
    foreach ($this->tags as $vocabulary) {
      foreach($vocabulary as $tid => $tag) {
        $this->tag_ids[] = $tid;
      }
    }
  }
  public function disambiguate() {

    if (!isset($this->tags)) {
      // TODO: Do what?
    }
    foreach ($this->tags as $vocabulary => $tids) {
      foreach($tids as $tid => $tag) {
        if ($tag['hits'] > 1) {
          $checked_tid = $this->checkRelatedTags($tag);
          if ($checked_tid != 0 && $checked_tid != $tid) {
            $temp = $this->tags[$vocabulary][$tid];
            $this->tags[$this->getVocabulary($checked_tid)][$checked_tid] = $temp;
            unset($this->tags[$vocabulary][$tid]);
          }
        }
      }
    }
    return $this->tags;
  }

  public function checkRelatedTags($tag) {
    $related_tags = $this->getRelatedTags($tag);
    $max_matches = 0;
    $current = 0;
    foreach ($related_tags as $tid => $rtids) {
      $matches = count(array_intersect($rtids, $this->tag_ids));
      if($matches > $max_matches) {
        $current = $tid;
        $max_matches = $matches;
      }
    }
    return $current;
  }
  public function getRelatedTags($tag) {
    $tagger_instance = Tagger::getTagger();
    $vocabularies = implode(',', array_keys($tagger_instance->getConfiguration('vocab_names')));
    $sql = sprintf("SELECT l.tid, l.name, GROUP_CONCAT(r.rtid SEPARATOR ', ') AS rtids FROM term_synonym AS l LEFT JOIN term_relations AS r ON l.tid = r.tid WHERE l.vid IN (%s) AND l.name = '%s' GROUP BY l.tid", $vocabularies, $tag['word']);
    $matches = array();
    $result = TaggerQueryManager::query($sql);
    if ($result) {
      while ($row = mysql_fetch_assoc($result)) {
        $matches[$row['tid']] = explode(',', $row['rtids']);
      }
    }
    return $matches;
  }

  public function getVocabulary($tid) {
    $tagger_instance = Tagger::getTagger();
    $sql = sprintf("SELECT c.vid FROM term_data AS c WHERE c.tid = %s LIMIT 0,1", $tid);
    $result = TaggerQueryManager::query($sql);
    $row = mysql_fetch_assoc($result);
    $vocab_names = $tagger_instance->getSetting('vocab_names');
    return $vocab_names[$row['vid']];
  }
}

