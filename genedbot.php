#!/usr/bin/php
<?php

require_once ( "gff2wd.php" ) ;

set_time_limit ( 60 * 1000 ) ; // Seconds
ini_set('memory_limit','6000M');
error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); # |E_ALL

if ( !isset($argv[1]) ) die ( "Species key required\n" ) ;
$sk = $argv[1] ;

$config = json_decode( ( file_get_contents ( __DIR__ . '/config.json' ) ) ) ;

if ( !isset($config->species->$sk) ) {
	die ( "Species key {$sk} not found in config.json\n" ) ;
}

$gff2wd = new GFF2WD ;
$gff2wd->gffj = (object) $config->species->$sk ;
if ( isset($argv[2]) ) $gff2wd->init($argv[2]) ;
else $gff2wd->init() ;

?>