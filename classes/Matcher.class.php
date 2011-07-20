<?php

require_once __ROOT__ . 'logger/TaggerLogManager.class.php';
require_once __ROOT__ . 'db/TaggerQueryManager.class.php';

abstract class Matcher {
  protected $matches;
  protected $numresults = 0;
  protected $tokens;
  protected $vocabularies;
  protected $nonmatches;
  protected $tagger;

  function __construct($tokens, $vocab_id_array) {
    $this->tagger = Tagger::getTagger();

    foreach($tokens as $token) {
      $this->tokens[strtolower($token->text)] = $token;
    }
    $this->matches = array();
    $this->nonmatches = array();
    $this->vocabularies = implode(', ', $vocab_id_array);
  }

  protected function term_query() {

    if (!empty($this->vocabularies) && !empty($this->tokens)) {
      $imploded_words = implode("','", array_keys($this->tokens));
      $unmatched = $this->tokens;

      // First we find synonyms
      $synonyms = array();
      $query = "SELECT tid, name FROM term_synonym WHERE name IN('$imploded_words') GROUP BY name";
      $result = TaggerQueryManager::query($query);
      while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $synonyms[$row['tid']][] = strtolower($row['name']);
        unset($unmatched[strtolower($row['name'])]);
        TaggerLogManager::logDebug("Synonym:\n" . print_r($row, TRUE));
      }
      $synonym_ids_imploded = implode("','", array_keys($synonyms));
      $imploded_words = implode("','", array_keys($unmatched));

      // Then we find the actual names of entities
      $query = "SELECT COUNT(tid) AS count, tid, name, vid FROM term_data WHERE vid IN($this->vocabularies) AND (name IN('$imploded_words') OR tid IN('$synonym_ids_imploded')) GROUP BY BINARY name";
      TaggerLogManager::logDebug("Match-query:\n" . $query);
      $result = TaggerQueryManager::query($query);

      while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        if($row['name'] != '') {
          $row_matches = array();
          $row_name_lowered = strtolower($row['name']);
          if (array_key_exists($row_name_lowered, $unmatched)) {
            unset($unmatched[$row_name_lowered]);
            $matchword = $row_name_lowered;
            $row_matches[] = $this->tokens[strtolower($matchword)];
          }
          if (array_key_exists($row['tid'], $synonyms)) {
            foreach ($synonyms[$row['tid']] as $synonym) {
              unset($unmatched[$synonym]);
              $row_matches[] = $this->tokens[$synonym];
            }
          }
          $match = Tag::mergeTags($row_matches);
          $match->realName = $row['name'];
          $this->matches[$row['vid']][$row['tid']] = $match;
        }
      }
      $this->nonmatches = $unmatched;
      TaggerLogManager::logVerbose("Matches:\n" . print_r($this->matches, TRUE));
      TaggerLogManager::logVerbose("Unmatched:\n" . print_r($this->nonmatches, TRUE));
    }
    else if(empty($this->vocabularies)) {
      throw new ErrorException('No vocabularies given.');
    }
    else if(empty($this->tokens)) {
      throw new ErrorException('No tokens given.');
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
