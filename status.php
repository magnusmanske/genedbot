#!/usr/bin/php
<?php

error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR);//E_ALL|

$config_path = 'https://www.genedb.org/data/datasets.json' ;
$cronfile = '/data/project/genedb/cron.tab' ;

require_once ( "gff2wd.php" ) ;

function load_types($gff2wd) {
	$gff_filename = $gff2wd->computeFilenameGFF() ;
    $gff = file_get_contents ( $gff_filename ) ;
	$gff = explode ( "\n" , gzdecode ( $gff ) ) ;
	$types = [] ;
	foreach ( $gff as $line ) {
		$line = explode ( "\t" , $line , 4 ) ;
		if ( isset($line[2]) ) $types[$line[2]] = isset($types[$line[2]])?$types[$line[2]]+1:1;
	}
	return $types ;
}

$config = json_decode (file_get_contents ( $config_path ) ) ;
foreach ( $config AS $group => $entries ) {
	foreach ( $entries AS $entry ) {
		if ( !isset($entry->wikidata_id) ) continue ;
		$entry->file_root = $entry->abbreviation ;
		$gff2wd = new GFF2WD ;
		$gff2wd->gffj = $entry ;

		//print "{$entry->abbreviation} : {$entry->wikidata_id}\n" ;
		$types = load_types($gff2wd);

		$type2q = $gff2wd->alternate_gene_subclasses ;
		$type2q['gene'] = 'Q7187' ;
		$type2q['mRNA'] = 'Q8054' ;
		$q2type = array_flip ( $type2q ) ;

		$out = [] ;
		foreach ( $types AS $k => $v ) $out[$k] = [ $v*1 , 0 ] ;

		$sparql = "SELECT ?p31 (count(?q) as ?cnt) { ?q wdt:P703 wd:{$entry->wikidata_id} ; wdt:P31 ?p31 } GROUP BY ?p31" ;
		$j = $gff2wd->tfc->getSPARQL($sparql) ;
		foreach ( $j->results->bindings AS $b ) {
			$q = $gff2wd->tfc->parseItemFromURL ( $b->p31->value ) ;
			$cnt = $b->cnt->value * 1 ;
			$k = $q2type[$q] ;
			if ( !isset($out[$k]) ) $out[$k] = [0,0];
			$out[$k][1] = $cnt ;
		}

		$important_types = ['gene','mRNA','pseudogene'];
		$important_diff = 0 ;
		//print "\n{$entry->abbreviation}\tGFF\tWikidata\n" ;
		foreach ( $out AS $k => $v ) {
			if ( !isset($type2q[$k]) ) continue ; // GFF stuff
			if ( in_array($k,$important_types) ) $important_diff += abs($v[0]-$v[1]) ;
			//print "{$k}\t{$v[0]}\t{$v[1]}\n" ;
		}
		print "{$entry->abbreviation}\t{$important_diff}\n" ;

		//exit(0);
	}
}

?>
