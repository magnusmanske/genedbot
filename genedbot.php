#!/usr/bin/php
<?php

$config_path = 'https://www.genedb.org/data/datasets.json' ;

require_once ( "gff2wd.php" ) ;

set_time_limit ( 60 * 1000 ) ; // Seconds
ini_set('memory_limit','6000M');
error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); # |E_ALL

if ( !isset($argv[1]) ) die ( "Species key required\n" ) ;
$sk = $argv[1] ;

$gff2wd = new GFF2WD ;
$gff2wd->qs->sleep = 1 ; # Seconds
$config = json_decode (file_get_contents ( $config_path ) ) ;
$found = false ;
foreach ( $config AS $group => $entries ) {
	foreach ( $entries AS $entry ) {
		if ( $entry->abbreviation != $sk ) continue ;
		if ( !isset($entry->wikidata_id) ) die ( "Species {$sk} found in {$config_path}, but has no Wikidata item; add a 'wikidata_id' value to the JSON object.\n" ) ;
		$entry->file_root = $entry->abbreviation ; # Check if abbreviation is the correct one
		$gff2wd->gffj = $entry ;
		$found = true ;
		break ;
	}
	if ( $found ) break ;
}
if ( !$found ) die ( "Species key {$sk} not found in {$config_path}\n" ) ;

if ( isset($argv[2]) ) {
$gff2wd->load_orth_data = false ; # DEBUG turn off in production
	$gff2wd->init($argv[2]) ;
} else {
	$gff2wd->init() ;
}

?>