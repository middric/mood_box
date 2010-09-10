<?php

class MoodBox {
  private $dictionary;
  private $torchbox;
  private $twitter;
  private $db;

  public function  __construct($consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret) {
    ini_set('mongo.native_long', 1);
    $mongo = new Mongo();
    $this->db = $mongo->php_mood_box;
    $this->twitter = new TwitterOAuth($consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret);
    $this->dictionary = new RID();
    $this->dictionary->load_dictionary('RID.CAT');

    $this->torchbox = new Torchboxers();
  }

  public function get_stream() {
    $rateLimit = $this->get_rate_limit();
    $staff = $this->torchbox->get_employees(1);
    if($rateLimit->remaining_hits - count($staff) < 0) {
      $minutesLeft = ($rateLimit->reset_time_in_seconds - time()) / 60;
      die ("Rate Limit - Please wait for another " . $minutesLeft . " minutes." . "\n");
    }

    $timelines = array();
    $since_id = $this->db->lastTweet->findOne();
    $current_id = 0;

    foreach($staff as $screen_name => $full_name) {
      $options = array(
        'screen_name' => $screen_name,
        'count' => 10,
        'include_rts' => 1
      );

      if(isset($since_id['since_id']) && $since_id['since_id'] > 0) {
        //$options['since_id'] = $since_id['since_id'];
      }

      $data = $this->twitter->get('statuses/user_timeline', $options);
      if(count($data) <= 0) {
        continue;
      }
      $timelines[$screen_name] = $data;
      for($i = 0; $i < count($timelines[$screen_name]); $i++) {
        if($timelines[$screen_name][$i]->id > $current_id) {
          $current_id = $timelines[$screen_name][$i]->id;
        }
      }
    }

    if(count($timelines) <= 0) {
      die ("No new tweets since tweet " . $since_id['since_id'] . "\n");
    }

    if($current_id > 0) {
      $this->set_since_id($current_id);
    }

    return $timelines;
  }

  public function analyze_stream($timelines) {
    $analysis = array();
    foreach($timelines as $user => $stream) {
      $text = '';
      for($i = 0; $i < count($stream); $i++) {
        $text .= $stream[$i]->text . ' ';
      }
      $data = $this->dictionary->analyze($text);
      if($data->word_count > 0) {
        $analysis[$user] = $data;
        $analysis[$user]->timestamp = time();
      }
    }
    if($analysis) {
      $this->set_analysis($analysis);
    }

    return $analysis;
  }

  public function calculate_temperament($analysis) {
    $days = 30;
    $exponent = 2 / ($days + 1);
    $users = array_keys($this->torchbox->get_employees());
    $analyzed = array_keys($analysis);
    $users = array_intersect($users, $analyzed);
    foreach($users as $key => $user) {
      $temperament = $this->get_temperament($user);

      // User doesnt have a temperament yet, prime it with their current emotional state
      if(!isset($temperament['user'])) {
        $options = array('user' => $user);
        $temperament = $this->get_analysis($options);
        foreach($temperament as $doc) {
          unset($doc['_id']);
          unset($doc['category_words']);
          unset($doc['category_count']);
          unset($doc['word_count']);
          $this->set_temperament($doc);
          break;
        }
        $temperament = $doc;
      }
      $newTemperament = array(
          'timestamp' => time(),
          'user' => $user,
      );
      foreach($temperament['category_percentage'] as $emotion => $value) {
        //(current day's closing price x Exponent) + (previous day's EMA x (1-Exponent))
        $newTemperament['category_percentage'][$emotion] = ($analysis[$user]->category_percentage[$emotion] * $exponent) + ($value * (1-$exponent));
      }
      $this->set_temperament($newTemperament);
    }

  }

  private function set_temperament($temperament) {
    $collection = $this->db->temperaments;
    return $collection->insert($temperament, true);
  }

  private function set_analysis($analysis) {
    $collection = $this->db->user_moods;
    foreach($analysis as $user => $data) {
      $data->user = $user;
      $collection->insert($data);
    }
  }

  private function get_temperament($user) {
    $collection = $this->db->temperaments;
    $conditions = array('user' => $user);
    $sort = array('timestamp' => -1);
    $temperament = $collection->find($conditions)->limit(1)->sort($sort);
    foreach($temperament as $temp) {
      return $temp;
    }
  }

  private function get_analysis($options) {
    $collection = $this->db->user_moods;
    $sort = array('timestamp' => -1);
    $data = $collection->find($options)->limit(1)->sort($sort);
    return $data;
  }

  private function set_since_id($since_id) {
    $collection = $this->db->lastTweet;
    $collection->remove(array(), true);
    $data = array("since_id" => $since_id);
    return $collection->insert($data, true);
  }

  private function get_rate_limit() {
    $limit = $this->twitter->get('account/rate_limit_status');

    return $limit;
  }
}

?>
