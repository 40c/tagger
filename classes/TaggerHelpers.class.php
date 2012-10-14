<?php
/**
 * Contains TaggerHelpers.
 */

/**
 * A container class for different helper functions.
 */
class TaggerHelpers {

  /**
   * Merges arrays recursively.
   *
   * Numeric keys are simply appended to the merged array and the key value is
   * lost. String keys that do not occur in multiple of the arrays given as
   * arguments are simply appended to the resulting array. String keys that do
   * occur in more than of the argument arrays are:
   * - If the value is an array: Merged recursively with other array values with
   *   the same key.
   * - Otherwise the value will be that of the last of the argument array that
   *   contains the key.
   *
   * Taken from:
   *  http://php.net/manual/en/function.array-merge-recursive.php
   *  walf 26-May-2011 05:23
   *
   * @param array ...
   *   Takes a number of arrays as arguments (at least 2).
   *   Example: @code $merged_array = arrayMergeRecursiveSimple($array1, $array2, ...); @endcode
   *
   * @return array
   *   The merged array.
   **/
  public static function arrayMergeRecursiveSimple() {
    if (func_num_args() < 2) {
      if (func_num_args() == 1) {
        $arrays = func_get_args();
        return $arrays[0];
      }
      else {
        return array();
      }
    }
    $arrays = func_get_args();
    $merged = array();
    while ($arrays) {
      $array = array_shift($arrays);
      if (!is_array($array)) {
        trigger_error(__FUNCTION__ .' encountered a non array argument', E_USER_WARNING);
        return;
      }
      //if (!$array) {
      //  continue;
      //}
      foreach ($array as $key => $value) {
        if (is_string($key)) {
          if (is_array($value) && array_key_exists($key, $merged) && is_array($merged[$key])) {
            $merged[$key] = call_user_func(array(__CLASS__, __FUNCTION__), $merged[$key], $value);
          } else {
            $merged[$key] = $value;
          }
        } else {
          $merged[] = $value;
        }
      }
    }
    return $merged;
  }


  /**
   * Merges arrays recursively but has the option for forced overrides.
   *
   * Numeric keys are simply appended to the merged array and the key value is
   * lost. String keys that do not occur in multiple of the arrays given as
   * arguments are simply appended to the resulting array. String keys that do
   * occur in more than of the argument arrays are:
   * - If the value is an array
   *   - and the key occurs in the first argument (the $override array): The
   *     value will be that of the last parameter that contains the key.
   *   - Otherwise it is merged recursively with values of the corresponding
   *     keys in the other argument arrays.
   * - Otherwise the value will be that of the last of the argument array that
   *   contains the key.
   *
   * Usage: @code $merged_array = arrayMergeRecursiveSimple($override, $array1, $array2, ...); @endcode
   *
   * Based on:
   *  http://php.net/manual/en/function.array-merge-recursive.php
   *  walf 26-May-2011 05:23
   *
   * @param array $override
   *   The first parameter of the function. Contains the keys that should be
   *   overridden instead of merged.
   * @param array ...
   *   The following array are merged according to the algorithm.
   *
   * @return array
   *   The merged array.
   **/
  public static function arrayMergeRecursiveOverride() {
    if (func_num_args() < 2) {
      trigger_error(__FUNCTION__ .' must have at least 2 arguments', E_USER_WARNING);
      return array();
    }

    $arrays = func_get_args();
    $override = $arrays[0];
    array_shift($arrays);

    $merged = array();
    while ($arrays) {
      $array = array_shift($arrays);
      if (!is_array($array)) {
        trigger_error(__FUNCTION__ .' encountered a non array argument', E_USER_WARNING);
        return;
      }
      //if (!$array) {
      //  continue;
      //}
      foreach ($array as $key => $value) {
        if (is_string($key)) {
          if (is_array($value) && array_key_exists($key, $merged) &&
              is_array($merged[$key]) && !in_array($key, $override)) {
            $merged[$key] = call_user_func(array(__CLASS__, __FUNCTION__),
                                           $override, $merged[$key], $value);
          } else {
            $merged[$key] = $value;
          }
        } else {
          $merged[] = $value;
        }
      }
    }
    return $merged;
  }

}

