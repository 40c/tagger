<?php

require_once __ROOT__ . 'classes/Token.class.php';

class EntityPreprocessor {
  private $tokens;
  private $named_entities;


  public function __construct($tokens) {
    $this->tokens = $tokens;
  }

  public function get_potential_named_entities() {
    if (!isset($this->named_entities)) {
      $this->named_entities = array();
      $this->extract_potential_named_entities();
    }
    return $this->named_entities;
  }

  /**
   * Simply attempts to attract place names or names.
   */
  private function extract_potential_named_entities() {
    for ($i = 0, $n = count($this->tokens); $i < $n; $i++) {
      $entity = NULL;
      $token = $this->tokens[$i];

      // If the token is uppercase, maybe it is a name or a place.
      if ($token->isUpperCase() && !$token->isStopWord() && !$token->isInitWord()) {
        $entity = array($i => &$this->tokens[$i]);

        // Look two words ahead.
        if (isset($this->tokens[$i +2])) {
          $next = $this->tokens[$i +2];

          while (($next->isUpperCase() || $next->isPrefixOrInfix())) {

            // Jump two words.
            $i += 2;
            $entity[$i] = &$this->tokens[$i];
            if (isset($this->tokens[$i+2])) {
              $next = $this->tokens[$i+2];
            }
            else {
              break;
            }
          }
        }
        $this->named_entities[] = $entity;
        unset($next);
        unset($token);
      }
    }
  }
}
