<?php
require_once __ROOT__ . 'classes/Matcher.class.php';

require_once __ROOT__ . 'classes/Token.class.php';
require_once __ROOT__ . 'classes/Tag.class.php';
require_once __ROOT__ . 'classes/EntityPreprocessor.class.php';

class NamedEntityMatcher extends Matcher {

  private $partialTokens;

  function __construct($partial_tokens, $ner_vocabs) {
    $this->tagger = Tagger::getTagger();

    $this->partialTokens = $partial_tokens;
    TaggerLogManager::logDebug("Partial tokens:\n" . print_r($this->partialTokens, TRUE));

    $entityPreprocessor = new EntityPreprocessor(&$this->partialTokens);
    $potential_entities = $entityPreprocessor->get_potential_named_entities();

    $potential_entities = $this->flattenTokens($potential_entities);
    TaggerLogManager::logDebug("Found potential entities:\n" . print_r($potential_entities, TRUE));

    $potential_entities = $this->mergeTokens($potential_entities);
    TaggerLogManager::logDebug("Merged:\n" . print_r($potential_entities, TRUE));

    parent::__construct($potential_entities, $ner_vocabs);
  }

  public function match() {


    $this->term_query();
    return;
  }

  private function flattenTokens($tokens) {
    $flattened_tokens = array();
    foreach ($tokens as $token_split) {
      $token = new Token(implode(' ', $token_split));
      reset($token_split);
      $first = current($token_split);
      $token->tokenNumber = $first->tokenNumber;
      $token->paragraphNumber = $first->paragraphNumber;
      foreach ($token_split as $key => $token_part) {
        if ($token_part->htmlRating > $token->htmlRating) {
          $token->htmlRating = $token_part->htmlRating;
        }
        $token->tokenParts = $token_split;
      }
      $flattened_tokens[] = $token;
    }
    return $flattened_tokens;
  }

  private function mergeTokens($tokens) {
    $tags = array();

    for ($i = 0, $n = count($tokens)-1; $i <= $n; $i++) {
      if (isset($tokens[$i])) {
        $tag = new Tag($tokens[$i]);
        for ($j = $i; $j <= $n; $j++) {
          if (isset($tokens[$j]) && $tag->text == $tokens[$j]->text) {
            $tag->rating += $tokens[$j]->rating;
            $tag->freqRating++;
            $tag->posRating += $tokens[$j]->posRating;
            $tag->htmlRating += $tokens[$j]->htmlRating;
            $tag->tokens[] = &$tokens[$j];
            unset($tokens[$j]);
          }
        }
        $tags[] = $tag;
      }
    }

    foreach ($tags as $tag) {
      $freq_rating = $this->tagger->getConfiguration('frequency_rating');
      $tag->rating /= 1 + (($tag->freqRating - 1) * (1 - $freq_rating));
    }

    return $tags;
  }

}
