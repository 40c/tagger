<?php

require_once __ROOT__ . 'classes/Token.class.php';

/*
 * Tags are the overall tags found in the text, i.e. tags are unique.
 * The tag points to the specific places in the text where it was found ($tokens)
 * and has a rating that is the sum of the ratings of the individual tokens.
 */
class Tag extends Token {

  public $tokens;

  public $realName;
  public $synonyms = array();

  public $posRating = 0;
  public $freqRating = 0;

  public function __construct($token) {
    if (is_string($token)) {
      parent::__construct($token);
    }
    elseif (is_a($token, 'Token')) {
      $this->text = $token->text;
    }
  }

  public static function mergeTags($tags) {
    $ret_tag = new Tag('');
    foreach ($tags as $tag) {
      $ret_tag->synonyms[]  = $tag->text;
      $ret_tag->rating     += $tag->rating;
      $ret_tag->freqRating += $tag->freqRating;
      $ret_tag->posRating  += $tag->posRating;
      $ret_tag->htmlRating += $tag->htmlRating;
      $ret_tag->tokens[]    = $tag->tokens;
    }
    return $ret_tag;
  }


}


