<?php

class RID {

  private $tree = array();
  private $categories = array();

  public function load_dictionary($path) {

    try {
      $file = fopen($path, 'r');
    } catch(Exception $e) {
      print "Error loading file.\n";
      var_dump($e);
      exit;
    }

    $primary_category = $secondary_category = $tertiary_category = $pattern = null;

    while(!feof($file)) {
      $buffer = fgets($file);
      $tabs = preg_match_all('/\t/', $buffer, $matches);
      $pattern = null;

      switch($tabs) {
        case 0:
          $primary_category = trim($buffer);
          $secondary_category = null;
          $tertiary_category = null;

          if($this->ensure_category($primary_category)) {
            $this->tree[$primary_category] = array();
          }
          else {
            $primary_category = null;
            $pattern = $this->distill_pattern($buffer);
          }
          break;
        case 1:
          $secondary_category = trim($buffer);
          $tertiary_category = null;

          if($this->ensure_category($secondary_category)) {
            $this->tree[$primary_category][$secondary_category] = array();
          }
          else {
            $secondary_category = null;
            $pattern = $this->distill_pattern($buffer);
          }
          break;
        case 2:
          $tertiary_category = trim($buffer);

          if($this->ensure_category($tertiary_category)) {
            $this->tree[$primary_category][$secondary_category][$tertiary_category] = array();
          }
          else {
            $tertiary_category = null;
            $pattern = $this->distill_pattern($buffer);
          }
          break;
        case 3:
            $pattern = $this->distill_pattern($buffer);
          break;
      }

      if($pattern) {
        if($tertiary_category) {
          $this->tree[$primary_category][$secondary_category][$tertiary_category][] = $pattern;
          $this->categories[] = $tertiary_category;
        }
        elseif($secondary_category) {
          $this->tree[$primary_category][$secondary_category][] = $pattern;
          $this->categories[] = $secondary_category;
        }
        elseif($primary_category) {
          $this->tree[$primary_category][] = $pattern;
          $this->categories[] = $primary_category;
        }
      }
    }
    $this->categories = array_unique($this->categories);
    fclose($file);
  }

  public function analyze($text) {
    $results = new RIDResults();

    $token = strtok($text, ' ');
    foreach($this->categories as $category) {
      $results->category_count[$category] = 0;
      $results->category_words[$category] = array();
      $results->category_percentage[$category] = 0;
    }
    
    while($token !== false) {
      $token = preg_replace('/[^a-zA-Z]*/', '', $token);
      $category = $this->get_category($token);
      
      if($category) {
        if(!isset($results->category_count[$category])) {
          $results->category_count[$category] = 0;
          $results->category_words[$category] = array();
        }
        $results->category_count[$category]++;
        $results->category_words[$category][] = $token;
        $results->word_count++;
      }

      $token = strtok(' ');
    }

    foreach($results->category_count as $key => $value) {
      $results->category_percentage[$key] = ($value / $results->word_count) * 100.0;
    }
    
    return $results;
  }

  public function get_categories() {
    return $this->categories;
  }

  private function get_category($token) {
    return $this->get_parent($this->tree, $token);
  }

  private function get_parent($array, $needle, $parent = null) {
    foreach ($array as $key => $value) {
      if(is_string($value)) {
        $regex = str_replace('\*', '.*', preg_quote($value));
      }
      if (is_array($value)) {
          $pass = $parent;
          if (is_string($key)) {
              $pass = $key;
          }
          $found = $this->get_parent($value, $needle, $pass);
          if ($found !== false) {
              return $found;
          }
      } else if (preg_match('#^' . $regex . '#', $needle)) {
          return $parent;
      }
    }
    return false;
  }

  private function distill_pattern($buffer) {
    $pattern = explode(' ', trim(strtolower($buffer)));
    return array_shift($pattern);
  }

  private function ensure_category($cat) {
    return !preg_match('/\([0-9]*\)/',$cat);
  }
}

class RIDResults {
  public $category_count = array();
  public $category_words = array();
  public $category_percentage = array();
  public $word_count = 0;
}

?>