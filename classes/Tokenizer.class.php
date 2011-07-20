<?php

require_once __ROOT__ . 'classes/Token.class.php';

class Tokenizer {
  public $words;
  public $tokens;

  public function __construct($text) {
    $text = str_replace("\n", " __newline__ ", $text);
    $text = str_replace("\r", " __newline__ ", $text);
    $this->words = preg_split("/([\s\?,\":\.«»'\(\)\!])/", $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach($this->words as $word) {
      if($word != '') {
        $this->tokens[] = new Token($word);
      }
    }
  }
}
