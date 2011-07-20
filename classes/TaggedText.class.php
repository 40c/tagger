<?php

require_once __ROOT__ . 'logger/TaggerLogManager.class.php';

require_once __ROOT__ . 'classes/NamedEntityMatcher.class.php';
require_once __ROOT__ . 'classes/Unmatched.class.php';
require_once __ROOT__ . 'classes/Tag.class.php';

class TaggedText {

  private $text;
  private $ner_vocab_ids;


  private $tokenParts;
  private $token;
  private $tags;
  private $tokenCount;
  private $paragraphCount;

  private $markedupText;
  private $intermediateHTML;


  private $rateHTML = TRUE;
  private $returnMarkedText = FALSE;
  private $nl2br = FALSE;
  private $return_uris = FALSE;
  private $return_unmatched = FALSE;
  private $disambiguate = FALSE;
  private $tagger;

  /**
   * Constructs a TaggedText object.
   *
   * @param string $text
   *   Text to be tagged.
   * @param array $rating
   *   An associative array containing one more of the keys:
   *     - frequency
   *     - position
   *     - tags
   *   Where each has a value between 0 and 1 to indicate the weighting of each
   *   rating method.
   * @param array $ner_vocab_ids
   *   The database-IDs of the vocabularies to be used.
   */
  public function __construct($text, $ner_vocab_ids = array(), $rate_html = FALSE, $return_marked_text = FALSE, $rating = array(), $disambiguate = FALSE, $return_uris = FALSE, $return_unmatched = FALSE, $nl2br = FALSE) {

    if (empty($text)) {
      throw new InvalidArgumentException('No text to find tags in has been supplied.');
    }


    // Change encoding if necessary.
    $this->text = $text;
    if (mb_detect_encoding($this->text) != 'UTF-8') {
      $this->text = utf8_encode($this->text);
    }

    // If no vocabulary database-ids are given - load them from config
    if (empty($ner_vocab_ids)) {
      $this->tagger = Tagger::getTagger();
      $ner_vocab_names = $this->tagger->getConfiguration('ner_vocab_names');
      $ner_vocab_ids = array_keys($ner_vocab_names);
      if (!isset($ner_vocab_ids) || empty($ner_vocab_ids)) {
        throw new ErrorException('Missing vocab definition in configuration.');
      }
    }

    // If no rating array is given - load it from configuration
    if (empty($rating)) {
      $this->tagger = Tagger::getTagger();
      $rating['frequency'] = $this->tagger->getConfiguration('frequency_rating');
      $rating['positional'] = $this->tagger->getConfiguration('positional_rating');
      $rating['HTML'] = $this->tagger->getConfiguration('HTML_rating');

      $rating['positional_minimum'] = $this->tagger->getConfiguration('positional_minimum_rating');
      $rating['positional_critical_token_count'] = $this->tagger->getConfiguration('positional_critical_token_count_rating');


      if ($key = array_search(FALSE, $rating, TRUE)) {
        throw new ErrorException('Missing ' . $key . '_rating definition in configuration.');
      }
    }

    $this->tagger = Tagger::getTagger();
    $this->markTagsStart = $this->tagger->getConfiguration('mark_tags_start');
    $this->markTagsEnd = $this->tagger->getConfiguration('mark_tags_end');



    $this->ner_vocab_ids = $ner_vocab_ids;
    $this->rating = $rating;
    $this->rateHTML = $rate_html;
    $this->returnMarkedText = $return_marked_text;
    $this->disambiguate = $disambiguate;
    $this->return_uris = $return_uris;
    $this->return_unmatched = $return_unmatched;
    $this->nl2br = $nl2br;
  }

  public function process() {
    TaggerLogManager::logVerbose("Text to be tagged:\n" . $this->text);

    // Tokenize - with/without HTML.
    if ($this->rateHTML) {
      require_once __ROOT__ . 'classes/HTMLPreprocessor.class.php';
      $preprocessor = new HTMLPreprocessor($this->text, $this->returnMarkedText);
    }
    else {
      require_once __ROOT__ . 'classes/PlainTextPreprocessor.class.php';
      $preprocessor = new PlainTextPreprocessor($this->text, TRUE);
    }
    $preprocessor->parse();
    $this->partialTokens = &$preprocessor->tokens;
    $this->paragraphCount = $preprocessor->paragraphCount;
    $this->tokenCount = $preprocessor->tokenCount;
    $this->intermediateHTML = $preprocessor->intermediateHTML;

    $this->partialTokens = $this->rateTokens($this->partialTokens);
    TaggerLogManager::logDebug("Tokens\n" . print_r($this->partialTokens, TRUE));

    // Named entity recognition
    $ner_matcher = new NamedEntityMatcher($this->partialTokens, $this->ner_vocab_ids);
    $ner_matcher->match();
    $this->tags = $ner_matcher->get_matches();


    // Capture unmatched tags
    if (FALSE != $this->return_unmatched) {
      $unmatched_words = $ner_matcher->get_nonmatches();
      $unmatched = new Unmatched($unmatched_words);
      $unmatched->logUnmatched();
      if ($this->return_unmatched) {
        //TODO - Process and return unmatched entities
      }
    }
    // Disambiguate
    if ($this->disambiguate) {
      require_once 'classes/Disambiguator.class.php';
      $disambiguator = new Disambiguator($this->tags);
      $this->tags = $disambiguator->disambiguate();
    }
    if ($this->return_uris) {
      $this->buildUriData();
    }

    // mark up found tags in HTML
    if ($this->returnMarkedText) {
      $this->markupText();
      TaggerLogManager::logDebug("Marked HTML:\n" . $this->markupText());
    }

  }

  private function rateTokens($tokens) {
    $min_pos_rating = (1 - $this->rating['positional_minimum']) * exp(-$this->tokenCount/$this->rating['positional_critical_token_count']) + $this->rating['positional_minimum'];
    $a = log(1 - (1 - $min_pos_rating) * $this->rating['positional']);

    foreach ($tokens as $token) {
      if ($this->paragraphCount >= 3) {
        $token->posRating = exp($a * (($token->paragraphNumber - 1) / ($this->paragraphCount - 1)));
      }
      else {
        $token->posRating = exp($a * (($token->tokenNumber - 1) / ($this->tokenCount - 1)));
      }

      // ATTENTION: this is the rating expression!
      $token->rating = (1 + $token->htmlRating * $this->rating['HTML']) * $token->posRating;

      if($token->tokenParts != NULL) {
        foreach ($token->tokenParts as $partial_token) {
          $partial_token->rating = $token->rating;
        }
      }
    }
    return $tokens;
  }

  public function getTags() {
    return $this->tags;
  }

  public function getTextWithTags() {
    return $this->markedupText;
  }

  private function markupText() {
    $this->markedupText = '';

    foreach ($this->tags as $category_tags) {
      foreach ($category_tags as $tag) {
        foreach ($tag->tokens as $synonym_tokens) {
          foreach ($synonym_tokens as $token) {
            if(!$token->hasBeenMarked) {
              reset($token->tokenParts);
              $start_token_part = &current($token->tokenParts);
              $end_token_part = &end($token->tokenParts);

              $start_token_part->text = $this->markTagsStart . $start_token_part->text;
              $end_token_part->text .= $this->markTagsEnd;

              $token->hasBeenMarked = TRUE;
            }
          }
        }
      }
    }

    foreach ($this->intermediateHTML as $element) {
      $this->markedupText .= $element;
    }

    return $this->markedupText;
  }



  private function buildUriData() {
    foreach ($this->tags as $cat => $tags) {
      foreach ($tags as $tid => $tag) {
        $uris = $this->fetchUris($tid);
        $this->tags[$cat][$tid]['uris'] = $uris;
      }
    }
  }
  private function fetchUris($tid) {
    $sql = sprintf("SELECT dstid, uri FROM linked_data_sources WHERE tid = %s ORDER BY dstid ASC", $tid);
    $result = TaggerQueryManager::query($sql);
    $uris = array();
    $lod_sources = $this->tagger->getConfiguration('lod_sources');
    while ($row = mysqli_fetch_assoc($result)) {
      $uris[$lod_sources[$row['dstid']]] = $row['uri'];
    }
    return $uris;
  }
}
?>
