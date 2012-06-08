<?php

require_once __ROOT__ . 'logger/TaggerLogManager.class.php';
require_once __ROOT__ . 'db/TaggerQueryManager.class.php';

class KeywordExtractor {
  public $words;
  public $tags;

  private $constant;

  function __construct($tokens) {
    $this->tagger = Tagger::getTagger();

    $this->word_tags = array();

    foreach (array_keys($tokens) as $key) {
      if (!preg_match('/^\w/u', $tokens[$key]->text)) {
        unset($tokens[$key]);
        continue;
      }
    }

    $this->word_tags = Tag::mergeTokens($tokens);
    foreach ($this->word_tags as $key => $tag) {
      $tag->rate();
      $this->word_tags[mb_strtolower($tag->text)] = $tag;
      unset($this->word_tags[$key]);
    }
    $this->word_count = array_sum(array_map(create_function('$tag', 'return $tag->freqRating;'), $this->word_tags));
  }

  public function determine_keywords() {
    $word_relations_table = Tagger::getConfiguration('db', 'word_relations_table');
    $lookup_table = Tagger::getConfiguration('db', 'lookup_table');

    $pdo = TaggerQueryManager::getLink();
    $imploded_words = array();
    foreach (array_keys($this->word_tags) as $token) {
      $imploded_words[] = $pdo->quote($token);
    }
    $imploded_words = implode(',', $imploded_words);

    // Find keyword relations from the words in the text
    $query = "SELECT * FROM $word_relations_table WHERE word IN ($imploded_words)";
    TaggerLogManager::logDebug("Query:\n" . $query);
    $result = TaggerQueryManager::query($query);
    if ($result) {
      $subjects = array();

      while ($row = TaggerQueryManager::fetch($result)) {
        // Words in the database are assumed to be lowercase already
        if (isset($this->word_tags[$row['word']])) {
          if (!isset($subjects[$row['tid']]['rating'])) { $subjects[$row['tid']]['rating'] = 0; }
            $subjects[$row['tid']]['rating'] += $row['score'] * $this->word_tags[$row['word']]->rating;

          // Save the score contribution of each word
          if (Tagger::getConfiguration('keyword', 'debug')) {
            $tag = clone $this->word_tags[$row['word']];
            $tag->rating *= $row['score'];
            $subjects[$row['tid']]['words'][] = $tag;
          }
        }
      }

      if (Tagger::getConfiguration('keyword', 'normalize')) {
        foreach (array_keys($subjects) as $key) {
          // Normalize
          $subjects[$key]['rating'] /= $this->word_count;
          // Threshold
          if ($subjects[$key]['rating'] < Tagger::getConfiguration('keyword', 'threshold')) {
            unset($subjects[$key]);
            continue;
          }
          // Convert to percentage
          $subjects[$key]['rating'] /= Tagger::getConfiguration('keyword', 'max_score');
          $subjects[$key]['rating'] *= 100;
          $subjects[$key]['rating'] = min($subjects[$key]['rating'], 100);
        }
      }

      //if (isset($subjects[0])) { unset($subjects[0]); }
      TaggerLogManager::logDebug("Keywords:\n" . print_r($subjects, true));

      // Get subject names and create tags
      if (!empty($subjects)) {
        $implode_subjects_ids = implode(',', array_keys($subjects));
        $vocab_ids = implode(',', Tagger::getConfiguration('keyword', 'vocab_ids'));

        $query = "SELECT tid, vid, name FROM $lookup_table WHERE tid IN ($implode_subjects_ids) AND vid IN ($vocab_ids)";
        $result = TaggerQueryManager::query($query);
        while ($row = TaggerQueryManager::fetch($result)) {
          $tag = new Tag($row['name']);
          $tag->rating = $subjects[$row['tid']]['rating'];
          $tag->realName = $row['name'];

          if (Tagger::getConfiguration('keyword', 'debug')) {
            $tag->tokens = array($tag->realName => $subjects[$row['tid']]['words']);
          }

          $this->tags[$row['vid']][$row['tid']] = $tag;
        }
      }
    }
    else {
      TaggerLogManager::logDebug("No keyword-relevant words found.");
    }
  }
}
