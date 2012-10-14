<?php
/**
 * @file
 * Contains KeywordImporter.
 */
ini_set('memory_limit', '1024M');

require_once __ROOT__ . 'db/TaggerQueryManager.class.php';

require_once __ROOT__ . 'classes/Tokenizer.class.php';
require_once __ROOT__ . 'classes/Stemmer.class.php';

/**
 * Implements functionality for generating keyword extraction lookup tables.
 */
class KeywordImporter {

  /**
   * Constructs a KeywordImporter object.
   */
  public function __construct() {
    $db_conf = Tagger::getConfiguration('db');
    $this->docstatsTable = $db_conf['docstats_table'];
    $this->wordstatsTable = $db_conf['wordstats_table'];
    $this->wordRelationsTable = $db_conf['word_relations_table'];
    $this->lookupTable = $db_conf['lookup_table'];

    $this->property = Tagger::getConfiguration('keyword', 'property');
    //$this->normalize = Tagger::getConfiguration('keyword', 'normalize');


    // Get total number of documents and words
    $query = "SELECT * FROM $this->docstatsTable LIMIT 0, 1";
    $result = TaggerQueryManager::query($query);
    $row = TaggerQueryManager::fetch($result);
    $this->totalDocCount = $row['doc_count'];
    $this->totalWordCount = $row['word_count'];
  }


  /**
   * Fills the tagger_lookup table with keywords.
   *
   * @param array $tids_keywords
   *   Array of keyword names {keys: tids, values: keywords}
   *   e.g. @code array(214 => 'Forest fires', ..) @endcode
   */
  protected function createKeywords($tids_keywords) {
    $fields = array('tid', 'vid', 'name', 'canonical');

    $values = array();
    foreach($tids_keywords as $tid => $keyword) {
      $values[] = array($tid, '16', $keyword, '1');
    }

    TaggerQueryManager::bufferedInsert($this->lookupTable, $fields, $values);
  }

  /**
   * Fills the tagger_word_relations table with words related to keywords (tids).
   *
   * @param array $tids_texts
   *   Array of arrays {keys: keyword tids, values: arrays texts}
   *   e.g. @code array(214 => array('Forest fire consumes city','Montana gets new seaplane'), ..) @endcode
   *   where 214 is a keyword tid and the array contains text related to that keyword
   * @param bool $check
   *   Check whether keyword exists in database.
   *
   */
  protected function createWordRelations($tids_texts, $check = TRUE) {

    $tids = array_keys($tids_texts);

    if ($check) {

      $keywords = array();
      foreach ($tids as $tid) {

        // Get keyword corresponding to $tid
        if ($name = $this->tidToName($tid)) {
          echo "$tid: $name\n";
          $keywords[$tid] = $name;
        }
        else {
          echo "'$tid': Not found.\n";
          unset($tids_texts[$tid]);
        }
      }


      // filter keywords that:
      // * have too few articles (keyword_non_candidates.txt)
      // * are already in the database (keywords_in_db.txt)
      // * or simply don't wanna have (add them yourself to keywords_non_candidates.txt)
      touch("keywords_non_candidates.txt");
      touch("keywords_in_db.$this->property.txt");
      $lines1 = file("keywords_non_candidates.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      $lines2 = file("keywords_in_db.$this->property.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      $lines = array_merge($lines1, $lines2);
      foreach($lines as $line) {
        list($tid, $name) = explode('|', $line);
        unset($keywords[$tid]);
      }

      $keyword_count = count($keywords);
    }
    else {
      $keyword_count = count(array_keys($tid));
    }


    //$keywords = array_slice($keywords, 0, $maximum_keyword_add_count, true);

    $property_esc = TaggerQueryManager::quote($this->property);

    $new_keywords = 0;

    $start = time();
    echo "Trying to add $keyword_count new keywords to the database...\n\n";

    foreach ($tids_texts as $tid => $texts) {

      // Create the related words to this keyword
      if (!empty($texts)) {
        echo "Adding $name...\n";
        $result = $this->createRelatedWords($tid, $texts, FALSE);

        if($result) {
          $new_keywords++;
        }
      }
    }
  }

  /**
   * Create related word data in the database for a single keyword.
   *
   * @param int $tid
   *   The `tid` of the keyword.
   * @param array $texts
   *   The texts related to the keyword.
   * @param bool $check
   *   Check if the keyword has too few texts. Defaults to TRUE.
   */
  public function createRelatedWords($tid, $texts, $check = TRUE) {

    if ($check) {

      // too few articles found
      if (count($texts) < Tagger::getConfiguration('keyword', 'minimum_number_of_texts')) {
        throw new Exception("Too few texts (" . count($texts) . " for " . $this->tidToName($tid));
      }
      /*
      echo "$hits articles.";
      if ($hits < 5) {
        echo " Too few. Skipping.\n";
        $file = fopen('keywords_non_candidates.txt', 'a');
        fwrite($file, $tid . '|' . $name . '|' . $hits . "\n");
        continue;
      }
      else {
        echo '\n';
      }
      */

    }

    // Check if $tid is a keyword in the DB
    $name = $this->tidToName($tid);
    echo "tid: $tid\n";

    // Check if words related to $tid are already in the DB
    $query = "SELECT tid, word FROM `$this->wordRelationsTable` WHERE tid = $tid";
    $result = TaggerQueryManager::query($query);
    if(TaggerQueryManager::fetch($result)) {
      echo "$name is already in the database. Skipping\n";
      $file = fopen("keywords_in_db.$this->property.txt", 'a');
      fwrite($file, $tid . '|' . $name . "\n");
      return FALSE;
    }

    $property_esc = TaggerQueryManager::quote($this->property);

    // Get and score the words related to this keyword

    $result = $this->findSignificantWords($texts);
    $hits = $result['doc_count'];
    $freq_array = &$result['freq_array'];


    echo "Number of words possibly related to $name: " . count($freq_array) . "\n";

    $freq_array = array_filter($freq_array, create_function('$v', 'return $v[\'' . $this->property . '\'] > 0.2; '));

    $values_array = array();

    $words_to_be_added = 0;
    foreach($freq_array as $value) {
      $values_array[] = array($value['word'], $tid, $value[$this->property],1);
      $words_to_be_added++;
    }

    $fields = array('word', 'tid', 'score', 'pass');
    TaggerQueryManager::bufferedInsert($this->wordRelationsTable, $fields, $values_array);

    // Added to DB
    $file = fopen("keywords_in_db.$this->property.txt", 'a');
    fwrite($file, "$tid|$name|$hits\n");
  }

  function keyword_update($tid, $texts) {

    if (count($texts) < Tagger::getConfiguration('keyword', 'minimum_number_of_texts')) {
      throw new Exception("Too few texts (" . count($texts) . " for " . $this->tidToName($tid));
    }

    $query = "DELETE FROM `$this->wordRelationsTable` WHERE tid = $tid";
    TaggerQueryManager::query($query);

    keyword_create($tid, $texts);
  }


  /**
   * Finds significant words in a collection of texts.
   *
   * @param array $texts
   *   The collection of texts to be analyzed.
   * @param string $prop
   *   The property on which to base word significance.
   * @param bool $normalize
   *   Whether to normalize such that the most significant word has a
   *   significance of 1 and all others have a fraction of that.
   *
   * @return array
   *   An array with two keys: `'doc_count'` and `'freq_array'`. `doc_count` is
   *   the number of texts considered and `freq_array` is an array with words as
   *   keys and their significance as values.
   */
  private function findSignificantWords($texts, $prop = FALSE, $normalize = FALSE) {

    if ($prop === FALSE) {
      $prop = $this->property;
    }

    //$timer = new Timer();

    $doc_count = 0;
    $word_count = 0;
    $doc_ids = array();
    $freq_array = array();

    //$timer->start();

    foreach ($texts as $text) {
      if($text != '') {
        $frequency = $this->scoreText($text);

        foreach ($frequency AS $key => $value) {
          $word_count += $value['word_count'];
          if (isset($freq_array[$key])) {
            $freq_array[$key]['word_count'] += $value['word_count'];
            $freq_array[$key]['doc_count']++;
          }
          else {
            $freq_array[$key]['word_count'] = $value['word_count'];
            $freq_array[$key]['doc_count'] = 1;

            $freq_array[$key]['word'] = $value['word'];
            $freq_array[$key]['doc_count_db'] = $value['doc_count'];
            $freq_array[$key]['word_freq_db'] = $value['word_freq_db'];
            $freq_array[$key]['doc_freq_db'] = $value['doc_freq_db'];
          }
        }
        $doc_count++;
      }
    }

    if ($prop == 'all') {
      $properties = array('diff', 'doc_freq', 'inner_doc_freq', 'outer_doc_freq',
                          'diff_outer_doc_freq', 'diff_outer_doc_freq_log');
    }
    else {
      $properties = array($prop);
    }

    foreach ($freq_array as $key => &$elem) {
      $temp_elem = $elem;

      $temp_elem['diff'] = $temp_elem['word_count']/$word_count - $temp_elem['word_freq_db'];

      $temp_elem['doc_freq'] = $temp_elem['doc_count_db']/$this->totalDocCount;

      // in how many related articles does this word occur? (relative to the number of related articles)
      // i.e. the percentage of related articles where this word occurs
      $temp_elem['inner_doc_freq'] = ($temp_elem['doc_count']-1)/$doc_count;
      // in how many related articles does this word occur?
      // (relative to the total number of articles where this word occurs)
      // i.e. the percentage of articles in which this word occurs that are related to this keyword
      $temp_elem['outer_doc_freq'] = min(($temp_elem['doc_count']-1)/(1+$temp_elem['doc_count_db']), 1);

      $temp_elem['malthe'] = $temp_elem['outer_doc_freq'] * $temp_elem['inner_doc_freq'];

      //$temp_elem['malt_x2'] = pow($temp_elem['doc_count'],2)/(1+$temp_elem['doc_count_db']);
      //$temp_elem['diff_malt_x2'] = $temp_elem['diff'] * $temp_elem['malt_x2'];
      //$temp_elem['diff_malt_x2_log'] = log(10000*$temp_elem['diff_malt_x2'], 2);
      //$temp_elem['diff_malt_x2_sqr'] = sqrt($temp_elem['diff_malt_x2']);

      $temp_elem['diff_outer_doc_freq'] = $temp_elem['diff'] * $temp_elem['outer_doc_freq'] * 10000;
      $temp_elem['diff_outer_doc_freq_log'] = log10($temp_elem['diff_outer_doc_freq']+1);

      if ($prop == 'all') {
        $elem = $temp_elem;
        foreach ($properties as $p) {
          if (is_nan($elem[$p])) {
            unset($freq_array[$key]);
          }
        }
      }
      else {
        if (is_nan($temp_elem[$prop])) {
          unset($freq_array[$key]);
        }
        else {
          $elem[$prop] = $temp_elem[$prop];
        }
      }
    }

    if ($normalize && $doc_count > 0) {
      foreach ($properties as $p) {
        // get the word with the highest score
        $val = max(array_map(create_function('$value', 'return $value[$p];'), $freq_array));
        $val = ($val == 0) ? 1 : $val;
        $factor = 1/$val;

        // divide any other score by that (the largest) score
        foreach($freq_array as &$value) {
          $value[$p] *= $factor;
        }
      }
    }

    //$timer->stop();

    //echo "Calculations took " . $timer->secsElapsed() . " seconds.\n";

    $result =  array();
    $result['doc_count'] = $doc_count;
    $result['freq_array'] = &$freq_array;

    return $result;
  }


  /**
   * Create and insert word statistics data into database.
   *
   * @param array $texts
   *   The texts on which to make word stats.
   *
   * @return array
   *   An array with the first value being the total document count and the
   *   second is total is the total word count.
   */
  public function createWordstats($texts) {
    $sql = "TRUNCATE $this->wordstatsTable";
    TaggerQueryManager::query($sql);

    list($this->totalDocCount, $this->totalWordCount) = $this->calculateWordstats($texts);

    // Set document frequency and word frequency for all words i.e. all rows in the DB
    $sql = "UPDATE $this->wordstatsTable
            SET doc_freq=doc_count/$this->totalDocCount,
                word_freq=word_count/$this->totalWordCount";
    TaggerQueryManager::query($sql);

    // Sets a table (docstats) with the total number of words and documents
    $sql = "TRUNCATE $this->docstatsTable";
    TaggerQueryManager::query($sql);
    $sql = "INSERT INTO `$this->docstatsTable` (doc_count,word_count)
            VALUES ($this->totalDocCount, $this->totalWordCount);";
    TaggerQueryManager::query($sql);

    return array($this->totalDocCount, $this->totalWordCount);
  }

  /**
   * Create word statistics data based on a collection of texts.
   *
   * Updates the wordstats table according to word frequencies in the text
   * collection.
   *
   * @param array $texts
   *   The collection of text on which to calculate word stats.
   *
   * @return array
   *   An array with the first value being the total document count and the
   *   second being the total word count.
   */
  public function calculateWordstats($texts) {

    $doc_count = 0;
    $word_count = 0;
    $overall_frequency = array();

    foreach ($texts as $text) {
      if ($text != '') {
        $frequency = $this->countWords($text);

        foreach ($frequency AS $key => $value){
          $word_count += $value['word_count'];
          if(!isset($overall_frequency[$key])) {
            $overall_frequency[$key]['word_count'] = $value['word_count'];
            $overall_frequency[$key]['doc_count'] = 1;
            $overall_frequency[$key]['doc_freq_sum'] = $value['word_freq'];
          } else {
            $overall_frequency[$key]['word_count'] += $value['word_count'];
            $overall_frequency[$key]['doc_count'] += 1;
            $overall_frequency[$key]['doc_freq_sum'] += $value['word_freq'];
          }
        }
        $doc_count++;

        if(($doc_count % 1000) == 0) {
          $this->updateWordstatsTable($overall_frequency);
          $overall_frequency = array();
        }
      }
    }

    return array($doc_count, $word_count);
  }


  /**
   * Updates the wordstats table.
   *
   * @param array $frequencies
   *   An associative array with words as keys and associative array containing
   *   word statistics as values.
   */
  private function updateWordstatsTable($frequencies) {

    $counter = 0;

    foreach ($frequencies AS $key => $value) {
      if($counter == 0) {
        $sql = "INSERT INTO $this->wordstatsTable (word, word_count, doc_count) VALUES\n";
      }
      else {
        $sql .= ', ';
      }

      $key = TaggerQueryManager::quote($key);
      $sql .= "($key, $value[word_count], $value[doc_count])";

      $counter++;

      if($counter == 1000) {
        $sql .= " ON DUPLICATE KEY UPDATE word_count=word_count+VALUES(word_count),
                                          doc_count=doc_count+VALUES(doc_count);";
        TaggerQueryManager::query($sql);
        $counter = 0;
      }
    }
    $sql .= " ON DUPLICATE KEY UPDATE word_count=word_count+VALUES(word_count),
                                      doc_count=doc_count+VALUES(doc_count);";
    TaggerQueryManager::query($sql);
  }

  // Calculate word scores in text
  private function scoreText($text) {
    static $db_cache = array();


    $frequency = $this->countWords($text);
    $words_to_lookup = array_diff(array_keys($frequency), array_keys($db_cache));

    $words = $words_to_lookup;

    // Get statistics for the words in the article
    $result = TaggerQueryManager::query("SELECT * FROM $this->wordstatsTable WHERE word IN (:words)", array(':words' => $words));

    $unmatched_database = array();
    $unmatched_words = $frequency;
    while ($row = TaggerQueryManager::fetch($result)) {
      // words in the database are assumed to be in mb-lowercase (mb_strtolower)
      $key = $row['word'];

      if(isset($frequency[$key])) {
        unset($unmatched_words[$key]);
        $cur_elem = &$frequency[$key];
        $cur_elem['word'] = $row['word'];
        $cur_elem['word_freq_db'] = $row['word_freq'];
        $cur_elem['doc_count'] = $row['doc_count'];
        $cur_elem['doc_freq_db'] = $row['doc_freq'];
      } else {
        $unmatched_database[] = $key;
      }
    }

    foreach ($unmatched_words as $key => $val) {
      unset($frequency[$key]);
    }

    return $frequency;
  }


  /**
   * Count the number of occurences of each word in a text.
   *
   * @param string $text
   *   The text which should be word counted.
   *
   * @return array
   *   An array where the keys are words and the values are their frequencies
   *   (the number of occurences).
   */
  private function countWords($text) {
    if ($text == '') {
      return array();
    }

    $words = Tokenizer::split_words(trim(mb_strtolower(strip_tags($text))));
    if (Tagger::getConfiguration('keyword', 'enable_stemmer')) {
      foreach ($words as &$word) {
        $word = Stemmer::stemWord($word);
      }
    }
    $frequency = array_count_values($words);
    $frequency = array_diff_key($frequency, Tagger::$stopwords);

    $word_count = array_sum($frequency);

    mb_regex_encoding("UTF-8");
    foreach ($frequency as $key => $value) {
      if (!mb_ereg_match('\w', $key)) {
        unset($frequency[$key]);
      }
    }
    //arsort($frequency);

    foreach($frequency as $key => $value){
      $frequency[$key] = array('word_count' => $value, 'word_freq' => $value/$word_count);
    }
    return $frequency;
  }

  /**
   * Find name corresponding to tid. (in the database)
   *
   * @param int $tid
   *   The tid to be looked up in the database.
   *
   * @return string|bool
   *   If the tid was found in the database the corresponding name is returned
   *   otherwise FALSE is returned.
   */
  public function tidToName($tid) {

    $query = "SELECT name FROM $this->lookupTable WHERE vid = 16 AND tid = $tid AND canonical = 1";
    try {
      $result = TaggerQueryManager::query($query);
    }
    catch(Exception $e) {
      return FALSE;
    }
    if ($row = TaggerQueryManager::fetch($result)) {
      return $row['name'];
    }
    else {
      return FALSE;
    }
  }

}

