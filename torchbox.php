<?php

class Torchboxers {
  private $employees = array();

  public function __construct() {
    $this->employees = array(
        'middric' => 'Rich Middleditch',
        'rjmackay' => 'Robbie Mackay',
        'garrettc' => 'Garrett Coakley',
        'jpstacey' => 'JP Stacey',
        'ollywillans' => 'Olly Willans',
        'tomd' => 'Tom Dyson',
        'victoriachan' => 'Victoria Chan',
        'MakeMineA3x' => 'Bryan Gullan',
        'westdotcodottt' => 'Matthew Westcott',
        'djharris' => 'David Harris',
        'paulsimongill' => 'Paul Gill',
        'edwardkay' => 'Ed Kay',
        'wesayso' => 'Wes West',
        'JonnyGrum' => 'Johnny Grum',
        'domeheid' => 'Chris Whalen',
        'jwebster' => 'James Webster',
        'bigdavejonnyt' => 'David Tomlinson',
        'rsalmonuk' => 'Rob Salmon',
        'digitaloop' => '',
        'davecranwell' => 'Dave Cranwell',
        'brighty' => '',
        'stanleytorchbox' => 'Stanley',
        'monami' => 'Ian Bellchambers',
        'benright22' => 'Ben Enright',
    );
  }

  public function get_employees($limit = 0) {
    if($limit) {
      return array_slice($this->employees, 0, $limit, TRUE);
    }
    return $this->employees;
  }
}

?>
