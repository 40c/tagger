<?php
  // Database connectiviy info.
  $tagger_conf['db']['type'] = 'dbtype'; // sqlite mysql mssql

  // Anything not SQLite
  if($tagger_conf['db']['type'] != 'sqlite') {
    $tagger_conf['db'] = array(
      'name' => '<database name>',
      'server' => '<server>',
      'username' => '<username>',
      'password' => '<password>',
      'type' => $tagger_conf['db']['type'],
    );
    
  } else {
    $tagger_conf['db'] = array(
      'path' => '<path to database>',
      'type' => $tagger_conf['db']['type'],
    );
  }

  // Names and ids of your vocabularies.
  $tagger_conf['vocab_names'] = array(
    13 => 'personer',
    17 => 'steder',
    15 => 'organisationer',
  );
  // Data sources for open linked data.
  $tagger_conf['lod_sources'] = array(
    1 => 'DBpedia',
    2 => 'en.wikipedia.org',
    3 => 'da.wikipedia.org',
    4 => 'GeoNames',
    5 => 'New York Times',
    6 => 'NYT search api'
    );
  // The hostnames of the sites that you would like to be allowed to call the
  // webservice. Leave an empty array if you want to allow access for all.
  $tagger_conf['service_allow_referer'] = array('tagger.dk');

