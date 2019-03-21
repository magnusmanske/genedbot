#!/usr/bin/php
<?php

$config_path = 'https://www.genedb.org/data/datasets.json' ;
$cronfile = '/data/project/genedb/cron.tab' ;

require_once ( "gff2wd.php" ) ;

$crontab = [] ;
$crontab[] = '10 2 * * * jsub -mem 2g -once -quiet /data/project/genedb/scripts/notify_on_changes.php' ;
$crontab[] = '#----' ;

$day = isset($argv[1]) ? $argv[1]*1 : 3 ;
$minute = 12 ;
$hour = 13 ;
$config = json_decode (file_get_contents ( $config_path ) ) ;
foreach ( $config AS $group => $entries ) {
	foreach ( $entries AS $entry ) {
		if ( !isset($entry->wikidata_id) ) continue ;
		$hour += 2 ;
		if ( $hour > 23 ) {
			$day++ ;
			if ( $day > 6 ) $day = 0 ;
			$hour -= 23 ;
			$minute += 13 ;
			while ( $minute > 59 ) $minute -= 60 ;
		}
		$key = $entry->abbreviation ;
		$crontab[] = "{$minute} {$hour} * * {$day} jsub -mem 8g -once -quiet -N {$key} /data/project/genedb/genedbot/genedbot.php {$key}" ; # Run on Wed
	}
}

$crontab = implode("\n",$crontab)."\n" ;
file_put_contents ( $cronfile , $crontab ) ;
exec ( 'crontab '.$cronfile ) ;

?>
