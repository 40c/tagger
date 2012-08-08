<?php
/**
 * @file
 * Contains NamedEntityMatcher.
 */
require_once __ROOT__ . 'db/TaggerQueryManager.class.php';
require_once __ROOT__ . 'classes/Token.class.php';
require_once __ROOT__ . 'classes/Tag.class.php';
require_once __ROOT__ . 'classes/EntityPreprocessor.class.php';

/**
 * Find named entities amongst tokens.
 */
class NamedEntityMatcher {

  protected $matches;
  protected $numresults = 0;
  private $tokens;
  protected $vocabularies;
  protected $nonmatches;
  private $partialTokens;

  /**
   * Constructs a NamedEntityMatcher object.
   *
   * @param array $partial_tokens
   *   Tokens that should be considered as possible named entities.
   */
  function __construct($partial_tokens) {
    $this->matches = array();
    $this->nonmatches = array();
    $this->vocabularies = Tagger::getConfiguration('named_entity', 'vocab_ids');

    $this->partialTokens = $partial_tokens;

    $entityPreprocessor = new EntityPreprocessor($this->partialTokens);
    $potential_entities = $entityPreprocessor->get_potential_named_entities();
    TaggerLogManager::logDebug("Found potential entities:\n" . print_r($potential_entities, TRUE));

    $potential_entities = TagProcessor::mergeTokens($potential_entities);
    TaggerLogManager::logDebug("Merged:\n" . print_r($potential_entities, TRUE));

    $this->setTokens($potential_entities);
  }

  /**
   * Finds named entities.
   *
   * Also check possible genitives and looks up those too.
   */
  public function match() {
    // We search for all tags 'straight up'.
    $this->term_query($this->tokens);
    // But maybe some tags were genitives, e.g. 'Rod Stewart's toys'
    // - a danish genitive ends in 's'.
    $nonmatches = array_filter($this->nonmatches, create_function('$token', 'return (substr($token->text, -1) != "s");'));
    $possible_genitives = array_filter($this->nonmatches, create_function('$token', 'return (substr($token->text, -1) == "s");'));
    TaggerLogManager::logDebug("Possible genitives:\n" . print_r($possible_genitives, TRUE));

    // We do a new search for the possibly unmatched genitives.
    if (!empty($possible_genitives)) {
      foreach ($possible_genitives as &$ends_with_s) {
        $ends_with_s->text = mb_strtolower(rtrim($ends_with_s->text, 's'));
      }
      $this->setTokens($possible_genitives);
      $this->term_query();
    }

    //Merge the nonmatches from the first query with nonmatches from the genetive query
    $this->nonmatches = array_merge($nonmatches, $this->nonmatches);

    return;
  }

  /**
   * Looks up the tokens in the database and returns any matching Tags.
   */
  private function term_query($tokens) {
    $lookup_table = Tagger::getConfiguration('db', 'lookup_table');

    if (!empty($this->vocabularies) && !empty($tokens)) {
      $unmatched = $tokens;

      // First we find synonyms
      $query = "SELECT tid, name FROM $lookup_table WHERE name IN(:words) AND vid IN(:vocabularies)";
      $args = array(':words' => array_keys($tokens), ':vocabularies' => array_values($this->vocabularies));
      $result = TaggerQueryManager::query($query, $args);

      $synonyms = array();
      while ($row = TaggerQueryManager::fetch($result)) {
        $synonyms[$row['tid']][] = mb_strtolower($row['name']);
        unset($unmatched[mb_strtolower($row['name'])]);
        TaggerLogManager::logDebug("Synonym:\n" . print_r($row, TRUE));
      }
      // Then we find the actual names of entities
      if (!empty($synonyms)) {

        $query = "SELECT COUNT(tid) AS count, tid, name, vid, GROUP_CONCAT(tid) AS tids FROM $lookup_table WHERE vid IN(:vocabularies) AND tid IN(:synonym_ids) AND canonical = 1 GROUP BY name";
        $args = array(':vocabularies' => array_values($this->vocabularies), ':synonym_ids' => array_keys($synonyms));
        $result = TaggerQueryManager::query($query, $args);

        while ($row = TaggerQueryManager::fetch($result)) {
          if($row['name'] != '') {
            $row_matches = array();
            $row_name_lowered = mb_strtolower($row['name']);
            if (array_key_exists($row_name_lowered, $unmatched)) {
              unset($unmatched[$row_name_lowered]);
              $row_matches[] = $tokens[$row_name_lowered];
            }
            if (array_key_exists($row['tid'], $synonyms)) {
              foreach ($synonyms[$row['tid']] as $synonym) {
                unset($unmatched[$synonym]);
                $row_matches[] = $tokens[$synonym];
              }
            }
            $match = TagProcessor::mergeTags($row_matches);
            $match->ambiguous = ($row['count'] > 1);
            if ($match->ambiguous) {
              $match->meanings = $row['tids'];
            }
            if (isset($this->matches[$row['vid']][$row['tid']])) {
              $this->matches[$row['vid']][$row['tid']] = TagProcessor::mergeTags(array($match, $this->matches[$row['vid']][$row['tid']]), $row['name']);
            } else {
              $match->realName = $row['name'];
              $this->matches[$row['vid']][$row['tid']] = $match;
            }
          }
        }
        TaggerLogManager::logVerbose("Matches:\n" . print_r($this->matches, TRUE));
      }
      $this->nonmatches = $unmatched;
      TaggerLogManager::logVerbose("Unmatched:\n" . print_r($this->nonmatches, TRUE));
    }
    else if(empty($this->vocabularies)) {
      throw new ErrorException('No vocabularies given to Matcher.');
    }
    else if(empty($tokens)) {
      throw new ErrorException('No tokens given to Matcher.');
    }
  }

  /**
   * Returns found `Tag`s.
   *
   * Named entities found in the database.
   *
   * @return array
   *   An array of `Tag`s: $this->matches.
   */
  public function get_matches() {
    return $this->matches;
  }

  /**
   * Returns the `Tag`s that were not found in the database.
   *
   * Possible named entities not found in the database.
   *
   * @return array
   *   An array of `Tag`s: $this->nonmatches.
   */
  public function get_nonmatches() {
    return $this->nonmatches;
  }

  /**
   * Sets tokens with lowercase keys
   */
  protected function setTokens($tokens) {
    foreach($tokens as $token) {
      $this->tokens[mb_strtolower($token->text)] = $token;
    }
  }

  /**
   * Disable changing $tokens directly.
   *
   * This function ensures that the $tokens array only have lowercase keys
   * because $tokens only can set via setTokens().
   */
  public function __set($name, $value) {
    if($name == 'tokens') {
      throw new Exception('Cannot set $tokens, use setTokens() instead.', 1);
    }
  }

}
