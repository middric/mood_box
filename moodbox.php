<?php

class MoodBox {
  private $dictionary;
  private $torchbox;
  private $twitter;
  private $db;

  public function  __construct($consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret) {
    // We need 64bit integer support, set mongos native_long to 1
    ini_set('mongo.native_long', 1);
    $mongo = new Mongo();
    // Set the mongo db to use
    $this->db = $mongo->php_mood_box;
    // Connect to twitter
    $this->twitter = new TwitterOAuth($consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret);
    // Create a RID object and load a dictionary file
    $this->dictionary = new RID();
    $this->dictionary->load_dictionary('RID.CAT');
    // Load up our staff members
    $this->torchbox = new Torchboxers();
  }

  /**
   * Get a stream of new tweets.
   *
   * @return array
   */
  public function get_stream() {
    // Beware Twitter rate limiting!
    $rateLimit = $this->get_rate_limit();
    $staff = $this->torchbox->get_employees();
    // Can we hit twitter for all our staff or do we wait?
    if($rateLimit->remaining_hits - count($staff) < 0) {
      $minutesLeft = ($rateLimit->reset_time_in_seconds - time()) / 60;
      die ("Rate Limit - Please wait for another " . $minutesLeft . " minutes." . "\n");
    }

    $timelines = array();
    $since_id = $this->db->lastTweet->findOne();
    $current_id = 0;

    // Iterate over our staff and query twitter for their latest tweets
    foreach($staff as $screen_name => $full_name) {
      // We want to get $screen_name's last 10 tweets, including retweets
      $options = array(
        'screen_name' => $screen_name,
        'count' => 10,
        'include_rts' => 1
      );
      // If we've run this before make sure we also only get $screen_name's tweets since the last time we ran
      if(isset($since_id['since_id']) && $since_id['since_id'] > 0) {
        $options['since_id'] = $since_id['since_id'];
      }

      // Get our tweet data
      $data = $this->twitter->get('statuses/user_timeline', $options);
      // Nothing new, move on
      if(count($data) <= 0) {
        continue;
      }
      $timelines[$screen_name] = $data;
      // Make sure our current_id is accurate (Twitter use sequential ids but we dont know what order our tweeters are posting in)
      for($i = 0; $i < count($timelines[$screen_name]); $i++) {
        if($timelines[$screen_name][$i]->id > $current_id) {
          $current_id = $timelines[$screen_name][$i]->id;
        }
      }
    }

    // Nothing new, boring
    if(count($timelines) <= 0) {
      die ("No new tweets since tweet " . $since_id['since_id'] . "\n");
    }

    if($current_id > 0) {
      $this->set_since_id($current_id);
    }

    return $timelines;
  }

  /**
   * Analyze a stream of tweets.
   *
   * @param array $timelines
   * @return array
   */
  public function analyze_stream($timelines) {
    $analysis = array();
    // Iterate over our timeline and perform a RID analysis on the data
    foreach($timelines as $user => $stream) {
      // First merge all the tweets into one long text string
      $text = '';
      for($i = 0; $i < count($stream); $i++) {
        $text .= $stream[$i]->text . ' ';
      }
      // Now analyze our long string
      $data = $this->dictionary->analyze($text);
      // If analysis found anything interesting attach the time
      if($data->word_count > 0) {
        $analysis[$user] = $data;
        $analysis[$user]->timestamp = time();
      }
    }
    // Save the analyzed data
    if($analysis) {
      $this->set_analysis($analysis);
    }

    return $analysis;
  }

  /**
   * Calcualte the temperament of analyses text based on previous temperaments
   * and the current emotional state
   *
   * @param array $analysis
   */
  public function calculate_temperament($analysis) {
    // Data older than 30 days isnt that relevent anymore so base the exponent on that
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
      $emotions = $this->dictionary->get_categories();
      foreach($emotions as $key => $emotion) {
        //(current day's closing price x Exponent) + (previous day's EMA x (1-Exponent))
        $percentageCurrent = 0;
        $percentageTemperament = 0;
        if(isset($analysis[$user]->category_percentage[$emotion])) {
          $percentageCurrent = $analysis[$user]->category_percentage[$emotion];
        }
        if(isset($temperament['category_percentage'][$emotion])) {
          $percentageTemperament = $temperament['category_percentage'][$emotion];
        }
        // Right, going to use an exponential moving average here
        $newTemperament['category_percentage'][$emotion] = ($percentageCurrent * $exponent) + ($percentageTemperament * (1-$exponent));
      }
      
      $this->set_temperament($newTemperament);
    }

  }

  /**
   * Save the temperament into the database.
   *
   * @param array $temperament
   * @return array
   */
  private function set_temperament($temperament) {
    $collection = $this->db->temperaments;
    return $collection->insert($temperament, true);
  }

  /**
   * Save the analysis into the database.
   *
   * @param array $analysis
   */
  private function set_analysis($analysis) {
    $collection = $this->db->user_moods;
    foreach($analysis as $user => $data) {
      $data->user = $user;
      $collection->insert($data);
    }
  }

  /**
   * Get user temperament data from the database. Expects twitter username.
   *
   * @param string $user
   * @return array
   */
  private function get_temperament($user) {
    $collection = $this->db->temperaments;
    $conditions = array('user' => $user);
    $sort = array('timestamp' => -1);
    $temperament = $collection->find($conditions)->limit(1)->sort($sort);
    foreach($temperament as $temp) {
      return $temp;
    }
  }

  /**
   * Get analysis details from the database, pass in an array of
   * query parameters.
   *
   * @param array $options
   * @return MongoCursorObject
   */
  private function get_analysis($options) {
    $collection = $this->db->user_moods;
    $sort = array('timestamp' => -1);
    $data = $collection->find($options)->limit(1)->sort($sort);
    return $data;
  }

  /**
   * Set the since_id value in the database.
   *
   * @param int $since_id
   * @return array
   */
  private function set_since_id($since_id) {
    $collection = $this->db->lastTweet;
    $collection->remove(array(), true);
    $data = array("since_id" => $since_id);
    return $collection->insert($data, true);
  }

  /**
   * Get twitter rate limiting object.
   *
   * @return object
   */
  private function get_rate_limit() {
    $limit = $this->twitter->get('account/rate_limit_status');

    return $limit;
  }
}

?>
