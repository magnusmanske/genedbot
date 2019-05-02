#!/usr/bin/php
<?php

# THIS WILL ONLY WORK ON TOOLFORGE

require_once ( __DIR__ . '/external/wikidata.php' ) ;
require_once ( __DIR__ . '/external/ToolforgeCommon.php' ) ;

$config_file = __DIR__ . '/notification.json' ;


function send_mail ( $to , $subject , $message ) {
	$from = "mm6@sanger.ac.uk" ;

	$headers  = 'MIME-Version: 1.0' . "\r\n";
	$headers .= 'Content-type: text/html; charset=utf-8' . "\r\n";
	$headers .= 'From: '.$from."\r\n".
    'Reply-To: '.$from."\r\n" .
    'X-Mailer: PHP/' . phpversion();

    if(mail($to, $subject, $message, $headers)){
#    	echo 'Your mail has been sent successfully.';
	} else{
	    echo 'Unable to send email. Please try again.';
	}
}

if ( !file_exists($config_file) ) $config = (object) [ 'last_ts' => '' ] ;
else $config = json_decode ( file_get_contents ( $config_file) ) ;
$today = date("Ymd") ;
print "$today / {$config->last_ts}\n" ;
if ( $today == $config->last_ts ) exit(0); # No news from the Western Front

$tfc = new ToolforgeCommon('notify on changes');

$db = $tfc->openDBwiki ( 'wikidatawiki' ) ;
$sql = "SELECT page_title,comment_text,revision_userindex.*" ;
#$sql .= ",(SELECT term_text FROM wb_terms WHERE term_full_entity_id=page_title AND term_entity_type='item' AND term_language='en' AND term_type='label') AS label" ;
$sql .= " FROM revision_userindex,page,`comment`" ;
$sql .= " WHERE rev_comment_id=comment_id AND rev_page IN (SELECT pl_from FROM pagelinks WHERE pl_namespace=120 AND pl_from_namespace=0 AND pl_title='P3382') AND page_id=rev_page" ;
$sql .= " AND rev_timestamp>='{$config->last_ts}' " ;
$sql .= " AND rev_timestamp<'$today' " ;
$sql .= " AND rev_user_text NOT IN ('" . implode("','",$config->user_whitelist) . "') ORDER BY rev_timestamp" ;

$message = '' ;
$result = $tfc->getSQL ( $db , $sql ) ;
$tmp = [] ;
while($o = $result->fetch_object()){
	$tmp[] = $o ;
	$qs[] = $o->page_title ;
}
$wil = new WikidataItemList ;
$q2label = $wil->getItemLabels($qs) ;

foreach ( $tmp AS $o ) {
	$label = $o->page_title ;
	if ( isset($q2label[$o->page_title])) $label = $q2label[$o->page_title] ;
	$t = "Item <a href='https://www.wikidata.org/wiki/{$o->page_title}'>{$o->page_title}</a> <i>{$label}</i><br/>\n" ;
	$t .= "changed on {$o->rev_timestamp} by <a href='https://www.wikidata.org/wiki/User:".urlencode($o->rev_user_text)."'>{$o->rev_user_text}</a>:\n" ;
	$t .= "<pre>{$o->comment_text}</pre>\n" ;
	$t .= "<a href='https://www.wikidata.org/w/index.php?title={$o->page_title}&type=revision&diff={$o->rev_id}&oldid={$o->rev_parent_id}'>See changes</a>" ;
	$message .= "<p>$t</p><hr/>\n" ;
}

# Send email, if necessary
if ( $message != '' ) {
	$message .= "<p>This list does <i>not</i> include edits by the following (whitelisted) users:<ul>\n" ;
	foreach ( $config->user_whitelist AS $user ) $message .= "<li><a href='https://www.wikidata.org/wiki/User:".urlencode($user)."'>{$user}</a></li>" ;
	$message .= "</ul></p>" ;
	$message = "<html><body>{$message}</body></html>" ;
	$subject = "Updates to GeneDB items on Wikidata between {$config->last_ts} and {$today}" ;
	send_mail ( $config->mailto , $subject , $message ) ;
}

# Write back config file
$config->last_ts = $today ;
file_put_contents ( $config_file , json_encode($config) ) ;

?>