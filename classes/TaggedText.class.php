<?php

require_once __ROOT__ . 'logger/TaggerLogManager.class.php';

require_once __ROOT__ . 'classes/NamedEntityMatcher.class.php';
require_once __ROOT__ . 'classes/Unmatched.class.php';
require_once __ROOT__ . 'classes/Tag.class.php';

class TaggedText {

  private $text;
  private $ner_vocab_ids;

  private $words;

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
  private $log_unmatched = FALSE;
  private $disambiguate = FALSE;
  private $tagger;

  /**
   * Constructs a TaggedText object.
   *
   * @param string $text
   *   Text to be tagged.
   * @param array $options
   *   See documentation of tagText funtion
   */
  public function __construct($text, $options) {

    if (empty($text)) {
      throw new InvalidArgumentException('No text to find tags in has been supplied.');
    }


    // Change encoding if necessary.
    $this->text = $text;
    if (mb_detect_encoding($this->text) != 'UTF-8') {
      $this->text = utf8_encode($this->text);
    }

    $this->tagger = Tagger::getTagger();
    $this->substitutionInMarkTags = $this->tagger->getConfiguration('mark_tags_substitution');
    $this->markTagsStart = $this->tagger->getConfiguration('mark_tags_start');
    $this->markTagsEnd = $this->tagger->getConfiguration('mark_tags_end');


    $this->ner_vocab_ids = $options['ner_vocab_ids'];
    $this->keyword_vocab_ids = $options['keyword_vocab_ids'];
    $this->rating = $options['rating'];
    $this->rateHTML = $options['rate_html'];
    $this->returnMarkedText = $options['return_marked_text'];
    $this->disambiguate = $options['disambiguate'];
    $this->return_uris = $options['return_uris'];
    $this->log_unmatched = $options['log_unmatched'];
    $this->nl2br = $options['nl2br'];
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
      $preprocessor = new PlainTextPreprocessor($this->text, $this->returnMarkedText);
    }
    $preprocessor->parse();
    $this->partialTokens = &$preprocessor->tokens;
    $this->paragraphCount = $preprocessor->paragraphCount;
    $this->tokenCount = $preprocessor->tokenCount;
    $this->intermediateHTML = $preprocessor->intermediateHTML;

    // Find words.
    foreach ($this->partialTokens as $token) {
      if (!preg_match("/([\s\?,\":\.«»'\(\)\!])/u", $token->text)) {
        $this->words[] = $token->text;
      }
    }

    // Do NER if NER-vocabs are provided
    if (count($this->ner_vocab_ids) > 0) {
      // Rate the partial tokens.
      foreach ($this->partialTokens as $token) {
        $token->rateToken($this->tokenCount, $this->paragraphCount, $this->rating);
      }
      TaggerLogManager::logDebug("Tokens\n" . print_r($this->partialTokens, TRUE));

      // Do named entity recognition: find named entities.
      $ner_matcher = new NamedEntityMatcher($this->partialTokens, $this->ner_vocab_ids);
      $ner_matcher->match();
      $this->tags = $ner_matcher->get_matches();

      // Rate the tags (named entities).
      $rating = $this->rating;
      $tag_rate_closure = function($tag) use ($rating) {
        $tag->rateTag($rating);
      };
      array_walk_recursive($this->tags, $tag_rate_closure);


      // Capture unmatched tags
      if ($this->log_unmatched) {
        $unmatched_entities = $ner_matcher->get_nonmatches();
        $unmatched = new Unmatched($unmatched_entities);
        $unmatched->logUnmatched();

      }
      // Disambiguate
      if ($this->disambiguate) {
        require_once 'classes/Disambiguator.class.php';
        $disambiguator = new Disambiguator($this->tags, $this->text);
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
     // Do NER if Keyword-vocabs are provided
    if (count($this->keyword_vocab_ids) > 0) {
      // Keyword extraction
      TaggerLogManager::logDebug("Words:\n" . print_r($this->words, true));

      require_once __ROOT__ . 'classes/KeywordExtractor.class.php';
      $keyword_extractor = new KeywordExtractor($this->words);
      $keyword_extractor->determine_keywords();
      $this->tags += $keyword_extractor->tags;
    }
  }

  public function getTags() {
    return $this->tags;
  }

  public function getMarkedupText() {
    return $this->markedupText;
  }

  private function markupText() {

    $this->markedupText = '';

    foreach ($this->tags as $category_tags) {
      foreach ($category_tags as $tag) {
        foreach ($tag->tokens as $synonym_tokens) {
          foreach ($synonym_tokens as $token) {
            if (!$token->hasBeenMarked) {
              reset($token->tokenParts);
              $start_token_part = &current($token->tokenParts);
              $end_token_part = &end($token->tokenParts);

              $tag_start = $this->markTagsStart;
              if ($this->substitutionInMarkTags) {
                $tag_start = str_replace("!!ID!!", array_search($start_token_part, $token->tokenParts), $tag_start);
              }

              $start_token_part->text = $tag_start . $start_token_part->text;
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
        $this->tags[$cat][$tid]->uris = $uris;
      }
    }
  }
  private function fetchUris($tid) {
    $db_conf = $this->tagger->getConfiguration('db');
    $linked_data_table = $db_conf['linked_data_table'];

    $sql = sprintf("SELECT dstid, uri FROM $linked_data_table WHERE tid = %s ORDER BY dstid ASC", $tid);
    $result = TaggerQueryManager::query($sql);
    $uris = array();
    $lod_sources = $this->tagger->getConfiguration('lod_sources');
    while ($row = TaggerQueryManager::fetch($result)) {
      $uris[$lod_sources[$row['dstid']]] = $row['uri'];
    }
    return $uris;
  }
}
?>
