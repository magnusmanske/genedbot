<?PHP

/* ATTENTION!
	These functions use a global $wil variable, and will create it if not present:
	$wil = new WikidataItemList () ;
*/

require_once ( __DIR__ . '/ToolforgeCommon.php' ) ;
require_once ( __DIR__ . '/quickstatements.php' ) ;
require_once ( __DIR__ . '/sourcemd.php' ) ;



/************************************************************************************************************************************************
* BEGIN ORCID STUFF
************************************************************************************************************************************************/

class ORCID {

	var $orcid_api_server = 'pub.orcid.org' ; # sandbox.
	var $orcid_api_version = 'v2.0' ;

	function addNamesToHash ( &$arr , $name ) {
		$name = preg_replace ( '/\s+/' , ' ' , $name ) ;
		$arr[$name] = $name ; // TODO better
		if ( preg_match ( '/^(.+) (.) (.+)$/' , $name , $m ) ) {
			$n2 = $m[1] . ' ' . $m[2] . '. ' . $m[3] ;
			$arr[$n2] = $n2 ;
			$n2 = $m[1] . ' ' . $m[3] ;
			$arr[$n2] = $n2 ;
		}

		if ( preg_match ( '/^(.{3,}) ([A-Z])(\S{2,}) (.+)$/' , $name , $m ) ) {
			$n2 = $m[1] . ' ' . $m[2] . '. ' . $m[4] ;
			$arr[$n2] = $n2 ;
			$n2 = $m[1] . ' ' . $m[4] ;
			$arr[$n2] = $n2 ;
		}
	}


	function runQueryOnAPI ( $path ) {
		$opts = array(
			'http'=>array(
				'method'=>"GET",
				'header'=>"Accept: application/json\r\n"
			)
		);
		$context = stream_context_create($opts);
		$url = "https://{$this->orcid_api_server}/{$this->orcid_api_version}/{$path}" ;
		$j = @file_get_contents ( $url , false , $context ) ;
		if ( !isset($j) or $j === null or $j == '' ) return ;
		return json_decode ( $j ) ;
	}

	function getWorksForORCID ( $orcid_id ) {
		$ret = [] ;
		$j = $this->runQueryOnAPI ( "{$orcid_id}/works" ) ;
		if ( !isset($j) ) return $ret ;
		if ( !isset($j->group) ) return $ret ;
		foreach ( $j->group AS $g ) {
			if ( !isset($g->{'external-ids'}) ) continue ;
			$x = [] ;
			foreach ( $g->{'external-ids'} AS $eg ) {
				foreach ( $eg AS $e ) {
					$x[$e->{'external-id-type'}] = $e->{'external-id-value'} ;
				}
			}
			$ret[] = $x ;
		}
		return $ret ;
	}

	function searchORCID ( $query ) {
		return $this->runQueryOnAPI ( 'search?q=' . urlencode($query) ) ;
	}

	function addNameVariants ( &$arr , $name ) {
		if ( trim($name) == '' ) return ;
		if ( preg_match ( '/^\S+$/' , $name ) ) return ; // Single name
		$this->addNamesToHash ( $arr , $name ) ;
		$this->addNamesToHash ( $arr , preg_replace ( '/^(.+), (.+)$/' , '$2 $1' , $name ) ) ;
	}

	function parsePersonFamilyName ( $person ) {
		$ret = '' ;
		if ( !isset($person->person) ) return $ret ;
		if ( !isset($person->person->name) ) return $ret ;
		$n = $person->person->name ;
		if ( isset($n->{'family-name'}) ) return $n->{'family-name'}->value ;
	}

	function parsePersonAliasesORCID ( $person ) {
		$ret = [] ;
		if ( !isset($person->person) ) return $ret ;

		if ( isset($person->person->name) ) {
			$n = $person->person->name ;
			if ( isset($n->{'given-names'}) and isset($n->{'family-name'}) ) {
				$this->addNameVariants ( $ret , $n->{'given-names'}->value . ' ' . $n->{'family-name'}->value ) ;
			}
			if ( isset($n->{'credit-name'}) ) {
				$this->addNameVariants ( $ret , $n->{'credit-name'}->value ) ;
			}
		}

		if ( !isset($person->person->{'other-names'}) ) return $ret ;
		if ( !isset($person->person->{'other-names'}->{'other-name'}) ) return $ret ;
		foreach ( $person->person->{'other-names'}->{'other-name'} AS $on ) {
			$this->addNameVariants ( $ret , $on->content ) ;
		}
		return $ret ;
	}

	function getPersonInfoORCID ( $orcid ) {
		$j = $this->runQueryOnAPI ( $orcid ) ;
		if ( !isset($j) or $j === null ) return ;
		return $j ;
	}

	function isValidORCID ( $orcid ) {
		return preg_match ( '/^0000-000(1-[5-9]|2-[0-9]|3-[0-4])\d{3}-\d{3}[\dX]$/' , $orcid ) ;
	}

	function initializeNamecount ( $named ) {
		$ret = [] ;
		foreach ( $named AS $n ) {
			$name = $n->mainsnak->datavalue->value ;
			if ( isset($ret[$name]) ) $ret[$name]++ ;
			else $ret[$name] = 1 ;
		}
		return $ret ;
	}

	// THIS IS HACKISH AND SHOULD BE FIXED!!
	function arrayHasString ( $arr , $s ) {
		if ( isset($arr[$s]) ) return true ;
		$s2 = $s ;
		foreach ( array ('é'=>'e','ó'=>'o','í'=>'i') AS $k => $v ) {
			$s2 = str_replace ( $k , $v , $s2 ) ;
			foreach ( $arr AS $x1 => $y1 ) {
				if ( strtolower ( $s2 ) == strtolower ( $x1 ) ) return true ;
			}
//			if ( isset($arr[$s2]) ) return true ;
		}
		return false ;
	}

	function checkNameInAuthorList ( $name , &$namecount , $names ) {
		$found = false ;
		if ( !isset($namecount[$name]) or $namecount[$name] > 1 ) return $found ;
		$name_variants = [] ;
		$this->addNamesToHash ( $name_variants , $name ) ;

		foreach ( $name_variants as $nv ) {
			if ( $this->arrayHasString ( $names , $nv ) ) $found = true ;
		}

		if ( !$found and preg_match ( '/^(\S)\.{0,1} (\S+)$/' , $name , $m ) ) {
			$cnt = 0 ;
			foreach ( $names AS $n ) {
				$pattern = '/^'.$m[1].'.* '.$m[2].'$/' ;
				if ( preg_match ( $pattern , $n ) ) $cnt++ ;
			}
			if ( $cnt == 1 ) $found = 1 ;
		}

		return $found ;
	}

} ;

/************************************************************************************************************************************************
* END ORCID STUFF
************************************************************************************************************************************************/


class PaperEditor {

	public $action_taken = '' ;
	public $is_bot_mode = false ;
	public $debugging = false ;
	public $testing = false ;
	public $create_new_authors = false ;

	var $tfc ;
	var $wil ;
	var $authors_with_orcid = [] ;

	function __construct( $tfc , $qs , $wil ) {
		if ( isset($tfc) ) $this->tfc = $tfc ;
		else $this->tfc = new ToolforgeCommon ;
		if ( isset($qs) ) $this->qs = $qs ;
		else $this->qs = getQS() ; // Assuming global function! TODO FIXME check function exists
		if ( isset($wil) ) $this->wil = $wil ;
		else $this->wil = new WikidataItemList () ;
	}

	public function enforceUncachedSPARQL ( &$sparql ) {
		$r = rand() ;
		$sparql = explode ( '{' , $sparql , 2 ) ;
		$sparql = $sparql[0] . " ?fakeVariable{$r} {" . $sparql[1] ;
	}
	public function fixISNI ( $isni ) {
		$isni = trim ( $isni ) ;
		if ( preg_match ( '/^(\d{4})(\d{4})(\d{4})(....)$/' , $isni , $m ) ) $isni = $m[1].' '.$m[2].' '.$m[3].' '.$m[4] ;
		return $isni ;
	}

	// $w = [ 'doi'=>'..' , 'pmid'=>'..' , 'pmid'=>'..' ]
	public function getOrCreateWorkFromIDs ( $w , $auto_add_authors = true ) {
		$cond = [] ;
		if ( isset($w['doi']) ) {
			$cond[] = '?q wdt:P356 "' . strtoupper($w['doi']) . '"' ;
			$cond[] = '?q wdt:P356 "' . strtolower($w['doi']) . '"' ;
		}
		if ( isset($w['pmid']) ) $cond[] = '?q wdt:P698 "' . $w['pmid'] . '"' ;
		if ( isset($w['pmc']) ) $cond[] = '?q wdt:P932 "' . preg_replace('/^\D+/','',$w['pmc']) . '"' ;
		if ( count($cond) == 0 ) return ;
		$sparql = "SELECT DISTINCT ?q { {" . implode ( '} UNION {' , $cond ) . "} }" ;
		$this->enforceUncachedSPARQL ( $sparql ) ;
		
		if($this->debugging)print "$sparql\n" ;
		$items = $this->tfc->getSPARQLitems ( $sparql , 'q' ) ;
		if ( count($items) > 0 ) { // An item for this work already exists
			if ( $auto_add_authors and isset($w['doi']) ) $this->addOrCreateAutorsForPaper ( $w['doi'] , $items[0] ) ;
			return $items[0] ;
		}
		$id = '' ;
		if ( isset($w['doi']) ) $id = $w['doi'] ;
		else if ( isset($w['pmc']) ) $id = $w['pmc'] ;
		else if ( isset($w['pmid']) ) $id = $w['pmid'] ;
		else return ;

		if($this->debugging)print "CREATING NEW WORK FOR {$id}\n" ;
		$smd = new SourceMD ( $id ) ;
		if ( $this->is_bot_mode ) $smd->verbose = false ;
		$commands = $smd->generateQuickStatements() ;
		if ( count($commands) < 5 ) return ; # Paranoia
		$commands = implode ( "\n" , $commands ) ;
		$this->qs->use_command_compression = true ;
		
		if($this->debugging)print_r ( $commands ) ;
		$tmp = $this->qs->importData ( $commands , 'v1' ) ;
		$this->qs->runCommandArray ( $tmp['data']['commands'] ) ;
		$q = $this->qs->last_item ;
		
		if($this->debugging)print "\n=> https://www.wikidata.org/wiki/{$q}\n" ;
		if ( $q == 'LAST' ) return ;
		$this->action_taken = 'CREATE' ;
		if ( $auto_add_authors && isset($w['doi']) ) $this->addOrCreateAutorsForPaper ( $w['doi'] , $q ) ;
		return $q ;
	}

	public function addOrCreateAutorsForPaper ( $doi , $paper_q = '' ) {
		$oc = new ORCID ;
		
		$ret = [] ;
		$doi = trim ( $doi ) ;
		
		// Get information from Wikidata
		$paper_q = $this->getItemForDOI ( $doi , $paper_q ) ;
		if ( !isset($paper_q) ) return $ret ; // DOI not on Wikidata

		$this->wil->loadItem ( $paper_q ) ;
		$paper_i = $this->wil->getItem ( $paper_q ) ;
		if ( !isset($paper_i) ) return $ret ; // Item not on Wikidata
		$author_claims = $paper_i->getClaims ( 'P50' ) ;
		$author_qs = [] ;
		foreach ( $author_claims AS $c ) $author_qs[] = $paper_i->getTarget ( $c ) ;
		$this->wil->loadItems ( $author_qs ) ;

		// Get information from ORCID
		$search_results = $oc->searchORCID ( '"'.$doi.'"' ) ;
		if ( !isset($search_results) or $search_results === null or !isset($search_results->result) ) {
			print "ORCID API failure for {$doi}\n" ;
			return $ret ;
		}

		$had_orcid = [] ;
		foreach ( $search_results->result AS $result ) {
			if ( !isset($result->{'orcid-identifier'}) ) continue ;
			if ( !isset($result->{'orcid-identifier'}->path) ) continue ;
			$orcid = $result->{'orcid-identifier'}->path ;
			if ( isset($had_orcid[$orcid]) ) continue ;
			$had_orcid[$orcid] = 1 ;
			if ( !$oc->isValidORCID($orcid) ) continue ; // Bad ORCID ID

			$person = $oc->getPersonInfoORCID ( $orcid ) ;
			if ( !isset($person) or $person === null ) continue ;
			$names = $oc->parsePersonAliasesORCID ( $person ) ;
			$new_candidates = [] ;

			// Name only
			$named = $paper_i->getClaims ( 'P2093' ) ;
			$namecount = $oc->initializeNamecount ( $named ) ;

			foreach ( $named AS $n ) {
				$name = $n->mainsnak->datavalue->value ;
				$found = $oc->checkNameInAuthorList ( $name , $namecount , $names ) ;
				if ( !$found ) { // Not in named authors
	#				print "Not found on ORICD author list: [$orcid] $name\n" ;
					continue ;
				}

				$num = $this->getNumberQualifier ( $n ) ;
				$author_q = $this->getOrCreateAuthorItem ( $name , $orcid , $person ) ;
				if ( !isset($author_q) or $author_q == '' ) continue ; // Paranoia

				// Remove string author, add P50 author
				$commands = '' ;
				$commands .= "-$paper_q\tP2093\t\"$name\"\n" ; # Re-activated with "stated as" (after https://www.wikidata.org/wiki/Wikidata_talk:WikiProject_Source_MetaData#Author_names )
				$commands .= "$paper_q\tP50\t$author_q" ;
				$commands .= "\tP1932\t\"$name\"" ; # Adding name as "stated as"
				if ( $num != '' ) $commands .= "\tP1545\t\"$num\"" ;
				$commands .= "\n" ;

				if ( $testing ) {
					print "---\n$commands\n" ;
					break ;
				}

				$this->qs->use_command_compression = true ;
				$tmp = $this->qs->importData ( $commands , 'v1' ) ;
				$this->qs->runCommandArray ( $tmp['data']['commands'] ) ;
				$action_taken = 'EDIT' ;

				break ; // Already found it
			}


			// Existing items
			$candidates = [] ;
			foreach ( $author_qs AS $q ) {
				$i = $this->wil->getItem($q) ;
				if ( !isset($i) ) continue ;
				foreach ( $names AS $n ) {
					if ( !$i->hasLabel($n) ) continue ;
					$candidates[$i->getQ()] = $orcid ;
				}
			}
			
			if ( count($candidates) == 0 ) {
				// No joy
				continue ;
			}
			if ( count($candidates) > 1 ) {
	//			logit ( "Multiple candidates for $orcid" ) ;
				continue ;
			}
			
			foreach ( $candidates AS $k => $v ) $ret[$k] = $v ;

		}
		return $ret ;
	}

	function getItemForDOI ( $doi , $paper_q = '' ) {
		if ( isset($paper_q) and preg_match ( '/^Q\d+$/' , $paper_q ) ) return $paper_q ;
		$sparql = 'SELECT DISTINCT ?q { VALUES ?dois { "'.strtoupper($doi).'" "'.strtolower($doi).'" } . ?q wdt:P356 ?dois }' ;
		$this->enforceUncachedSPARQL ( $sparql ) ;
		$items = $this->tfc->getSPARQLitems ( $sparql ) ;
		if ( count($items) != 1 ) return ;
		$paper_q = $items[0] ;
		return $paper_q ;
	}

	function getNumberQualifier ( $n ) {
		$num = '' ;
		if ( isset($n->qualifiers) ) {
			$num = $n->qualifiers ;
			if ( !isset($num) || !isset($num->P1545) ) {}
			else $num = $num->P1545[0]->datavalue->value ;
		}
		return $num ;
	}

	function getOrCreateAuthorItem ( $name , $orcid , $person ) {
		if ( isset($person->{'person'}) ) $person = $person->{'person'} ;

		// Try internal cache
		if ( $orcid != '' and isset($this->authors_with_orcid[$orcid]) ) return $this->authors_with_orcid[$orcid] ;

		// Try 
		if ( $orcid != '' ) {
			$sparql = "SELECT ?q { ?q wdt:P496 '{$orcid}' }" ;
			$items = $this->tfc->getSPARQLitems ( $sparql , 'q' ) ;
			if ( count($items) > 0 ) {
				$this->authors_with_orcid[$orcid] = $items[0] ;
				return $items[0] ;
			}
	 	}

		if ( !$this->create_new_authors ) return ;

		// Create new item
		$commands = '' ;
		$commands .= "CREATE\n" ;
		$commands .= "LAST\tLen\t\"$name\"\n" ;
		$commands .= "LAST\tP496\t\"$orcid\"\n" ;
		$commands .= "LAST\tP31\tQ5\n" ;
		if ( isset($person->{'researcher-urls'}) and isset($person->{'researcher-urls'}->{'researcher-url'}) ) {
			$found_url = false ;
			$fallback_command = '' ;
			foreach ( $person->{'researcher-urls'}->{'researcher-url'} AS $x ) {
				if ( !isset($x) or !isset($x->{'url-name'})  or !isset($x->url) or !isset($x->url->value) ) continue ;
				$url = $x->url->value ;
				$cmd = "LAST\tP856\t\"$url\"\n" ;
				if ( $fallback_command == '' ) $fallback_command = $cmd ;
				if ( !isset($x) ) continue ;
				if ( !isset($x->{'url-name'}) ) continue ;
				if ( !is_object($x->{'url-name'}) ) continue ;
				$key = strtolower(preg_replace('/\s+/','',$x->{'url-name'}->value)) ;
				if ( $key != 'homepage' and $key != 'personalhomepage' and $key != 'personalwebsite' ) continue ;
				$commands .= $cmd ;
				$found_url = true ;
				break ;
			}
		}
		if ( isset($person->{'external-identifiers'}) and isset($person->{'external-identifiers'}->{'external-identifier'}) ) {
			foreach ( $person->{'external-identifiers'}->{'external-identifier'} AS $x ) {
				$v = $x->{"external-id-value"} ;
				if ( $x->{'external-id-type'} == 'Scopus Author ID' ) {
					$commands .= "LAST\tP1153\t\"{$v}\"\n" ;
				}
			}
		}
		
		if ( $testing ) {
			print_r ( $commands ) ;
			return 'SOME_NEW_Q' ;
		}

		// Create new author, or use existing one
		$this->qs->use_command_compression = true ;
		$tmp = $this->qs->importData ( $commands , 'v1' ) ;
		$tmp['data']['commands'] = $this->qs->compressCommands ( $tmp['data']['commands'] ) ;
		$this->qs->runCommandArray ( $tmp['data']['commands'] ) ;
		$author_q = $this->qs->last_item ;
		if ( !isset($author_q) or $author_q == '' ) return ; // A problem
		$this->authors_with_orcid[$orcid] = $author_q ;
		return $author_q ;
	}

	// SEE https://pub.sandbox.orcid.org/v2.0/#!/Public_API_v2.0/viewRecord

} ;
/*

function getBookTitleFromISBN ( $isbn ) {
	$url = "https://openlibrary.org/api/books?bibkeys=ISBN:{$isbn}&callback=mycallback" ;
	$result = @file_get_contents ( $url ) ;
	if ( !isset($result) or $result === null or $result == '' ) return '' ;
	$result = preg_replace ( '|^.+?(\{.+\})[^\}]+$|' , '$1' , $result ) ;
	if ( !isset($result) or $result === null or $result == '' ) return '' ;
	$j = @json_decode ( $result ) ;
	if ( !isset($j) or $j === null or $j == '' ) return '' ;
	$title = '' ;
	foreach ( $j AS $k => $v ) {
		if ( !isset($v->info_url) ) continue ;
		if ( !preg_match ( '|^https://openlibrary.org/[^/]+/[^/]+/(.+)$|' , $v->info_url , $m ) ) continue ;
		$title = str_replace ( '_' , ' ' , $m[1] ) ;
		break ;
	}
	return $title ;
}

$isbn_cache = [] ;
function getOrCreateBookFromISBN ( $isbn , $title , $description = '' ) {
	global $tfc , $isbn_cache , $did_create_new_item ;
	$did_create_new_item = false ;
	if ( !isset($tfc) ) $tfc = new ToolforgeCommon ;
	$isbn = preg_replace ( '/[\s-]/' , '' , $isbn ) ;
	if ( isset($isbn_cache[$isbn]) ) return $isbn_cache[$isbn] ;
	$j = json_decode ( $tfc->doPostRequest ( 'https://isbn.org/xmljson.php' , ['request_code'=>'isbn_convert','request_data'=>json_encode(['isbn'=>$isbn])] ) ) ;
	if ( !isset($j) or $j === null or !isset($j->results) ) return ;

	$values = [ $isbn ] ;
	if ( isset($j->results->isbn) ) $values[] = $j->results->isbn ;
	if ( isset($j->results->converted_isbn) ) $values[] = $j->results->converted_isbn ;
	$r = rand() ;
	$sparql = "SELECT DISTINCT ?q ?randomVar{$r} { VALUES ?isbn { '" . implode ( "' '" , $values ) . "' } { ?q wdt:P212 ?isbn } UNION { ?q wdt:P957 ?isbn } }" ;
	$items = $tfc->getSPARQLitems ( $sparql , 'q' ) ;
	if ( count($items) == 1 ) {
		$isbn_cache[$isbn] = $items[0] ;
		return $items[0] ;
	}
	if ( count($items) > 1 ) return ; // Too many books!

	$isbn_good = $j->results->isbn ;
	$isbn_prop = ( strlen($isbn) == 10 ) ? 'P957' : 'P212' ;

	if ( $title == '' ) $title = getBookTitleFromISBN ( $isbn ) ;

	$commands = [] ;
	$commands[] = 'CREATE' ;
	if ( $title != $isbn ) $commands[] = "LAST\tLen\t\"{$title}\"" ;
	if ( $description != '' ) $commands[] = "LAST\tDen\t\"{$description}\"" ;
	$commands[] = "LAST\tP31\tQ3331189" ;
	$commands[] = "LAST\t{$isbn_prop}\t\"{$isbn_good}\"" ;
	$ret = runCommands ( $commands ) ;
	if ( isset($ret) and $ret != '' ) {
		$did_create_new_item = true ;
		$isbn_cache[$isbn] = $ret ;
	}
	return $ret ;
}



function add_person_ids_from_orcid ( $q ) {
	global $wil ;
	if ( !isset($wil) ) $wil = new WikidataItemList () ;
	$wil->loadItem ( $q ) ;
	$i = $wil->getItem ( $q ) ;
	if ( !$i->hasTarget ( 'P31' , 'Q5' ) ) return "Not a human: $q" ;

	$orcid = $i->getFirstString ( 'P496' ) ;
	if ( $orcid == '' ) return "No ORCID: $q" ;
	
	$h = file_get_contents ( "https://orcid.org/$orcid" ) ;
	$h = preg_replace ( '/\s+/' , ' ' , $h ) ;
	
	$candidates = [] ;
	
	if ( preg_match ( '/<div class="bio-content">(.+?)<\/div>/' , $h , $m ) ) {
		$bio = $m[0] ;
		$bio_patterns = array (
			'P214' => '/viaf.org\/viaf\/(\d+)/'
		) ;
		foreach ( $bio_patterns AS $prop => $pattern ) {
			if ( preg_match ( $pattern , $bio , $n ) ) $candidates[$prop][] = trim($n[1]) ;
		}
	}
	
	preg_match_all ( '/<a[^>]+href="(.+?)".*?>(.+?)<\/a>/' , $h , $m ) ;
//	print_r ( $m ) ;

	$url_patterns = array (
		'P4016' => '/www.slideshare.net\/(.+)\/{0,}/' ,
		'P3829' => '/publons.com\/author\/(\d+)/' ,
		'P1960' => '/scholar.google.[a-z\.]+\/citations.*[\?\&]user=([^\&\/]+)/' ,
		'P3835' => '/www.mendeley.com\/profiles\/([^\/]+)/' ,
		'P2002' => '/twitter.com\/([^\/]+)/' ,
		'P2035' => '/(https{0,1}:\/\/www.linkedin.com\/profile\/view\?id=[^\&\/]+)/' ,
		'P2038' => '/www.researchgate.net\/profile\/([^\&\/]+)/' ,
		'P2013' => '/www.facebook.com\/([^\&\/]+)/' ,
		'P2847' => '/plus.google.com\/([^\&\/]+)/' ,
		'P553/Q51711/P554' => '/www\.quora\.com\/profile\/([^\&\/]+)/'
	) ;
	
	$t_patterns = array (
		'P1053' => '/^ResearcherID:\s*(.+?)$/' ,
		'P1153' => '/^Scopus Author ID:\s*(.+?)$/' ,
		'P213' => '/^ISNI:\s*(.+?)$/',
		'P2798' => '/^Loop profile:\s*(.+?)$/',
	) ;
	
	foreach ( $m[0] AS $k => $link ) {
		$url = $m[1][$k] ;
		$t = $m[2][$k] ;
		foreach ( $t_patterns AS $prop => $pattern ) {
			if ( preg_match ( $pattern , $t , $n ) ) $candidates[$prop][] = trim($n[1]) ;
		}
		foreach ( $url_patterns AS $prop => $pattern ) {
			if ( preg_match ( $pattern , $url , $n ) ) $candidates[$prop][] = trim($n[1]) ;
		}
	}

	if ( isset ( $candidates['P213'] ) ) {
		foreach ( $candidates['P213'] AS $k => $v ) {
			$candidates['P213'][$k] = fixISNI ( $v ) ;
		}
	}

	$commands = '' ;
	
	if ( !$i->hasClaims ( 'P734') ) {
		$oc = new ORCID ;
		$person_from_api = $oc->getPersonInfoORCID ( $orcid ) ;
		if ( isset($person_from_api) and $person_from_api !== null ) {
			$family_name = $oc->parsePersonFamilyName ( $person_from_api ) ;
			if ( $family_name != '' ) {
				$search_query = "{$family_name} haswbstatement:P31=Q101352" ;
				$url = "https://www.wikidata.org/w/api.php?action=query&list=search&srnamespace=0&format=json&srsearch=" . urlencode($search_query) ;
				$j = json_decode ( file_get_contents ( $url ) ) ;
				if ( isset($j->query) and isset($j->query->search) and count($j->query->search) == 1 ) {
					$last_name_q = $j->query->search[0]->title ;
					$commands .= "$q\tP734\t$last_name_q\n" ;
				}
			}
		}
	}


	// Simple properties
	foreach ( $candidates AS $prop => $values ) {
		if ( !preg_match ( '/^P\d+$/' , $prop ) ) continue ;
		$existing = $i->getStrings ( $prop ) ;
		foreach ( $values AS $v ) {
			$exists = false ;
			foreach ( $existing AS $e ) {
				if ( trim(strtolower($e)) == trim(strtolower($v)) ) $exists = true ;
			}
			if ( $exists ) continue ;
			$commands .= "$q\t$prop\t\"$v\"\n" ;
		}
	}

	// Qualifier things
	foreach ( $candidates AS $prop => $values ) {
		if ( !preg_match ( '/^(P\d+)\/(Q\d+)\/(P\d+)$/' , $prop , $n ) ) continue ;
		$p1 = $n[1] ;
		$q1 = $n[2] ;
		$p2 = $n[3] ;
		$existing = $i->getClaims ( $p1 ) ;
		foreach ( $values AS $v ) {
			$exists = false ;
			foreach ( $existing AS $c ) {
				if ( $c->mainsnak->datavalue->value->id != $q1 ) continue ;
				if ( !isset($c->qualifiers) or !isset($c->qualifiers->$p2) ) continue ;
				foreach ( $c->qualifiers->$p2 AS $qual ) {
					if ( trim(strtolower($v)) == trim(strtolower($qual->datavalue->value)) ) $exists = true ;
				}
			}
			if ( $exists ) continue ;
			$commands .= "$q\t$p1\t$q1\t$p2\t\"$v\"\n" ;
		}
	}
	
	if ( preg_match_all ( '/<span name="other-name">\s*(.+?)\s*<\/span>/' , $h , $m ) ) {
		$aliases = $i->getAllAliases() ;
		foreach ( $i->j->labels AS $lang => $x ) $aliases[$lang][] = $x->value ; # Adding labels
//		print "<pre>" ; print_r ( $aliases ) ; print "</pre>" ;
		foreach ( $m[1] AS $n ) {
			$n = trim ( preg_replace ( '/^(.+), (.+?)$/' , '$2 $1' , $n ) ) ;
			if ( preg_match ( '/^.\. /' , $n ) ) continue ;
			if ( preg_match ( '/\[email protected\]/' , $n ) ) continue ;
			$found = false ;
			foreach ( $aliases AS $lang => $al ) {
				if ( !in_array ( $n , $al ) ) continue ;
				$found = true ;
				break ;
			}
			if ( $found ) continue ;
			if ( trim($n) == '{{otherName}}' ) continue ;
			$commands .= "$q\tAen\t\"$n\"\n" ;
		}
	}
	
	if ( $commands != '' ) {
		$qs = getQS() ;
		$qs->use_command_compression = true ;
		$tmp = $qs->importData ( $commands , 'v1' ) ;
		$qs->runCommandArray ( $tmp['data']['commands'] ) ;
		return "Added to $q" ;
	}
	
	return "OK" ;
}


//________________________________________________________________________________________________________________________________________________________________





function initAuthorsWithORCID () {
	global $authors_with_orcid ;
	$authors_with_orcid = [] ;
	$sparql = "SELECT ?q ?orcid { ?q wdt:P496 ?orcid }" ;
	enforceUncachedSPARQL ( $sparql ) ;
	$j = getSPARQL ( $sparql ) ;
	foreach ( $j->results->bindings AS $b ) {
		$q = preg_replace ( '/^.+\/Q/' , 'Q' , $b->q->value ) ;
		$authors_with_orcid[$b->orcid->value] = $q ;
	}
}

function qs2dois ( $list ) {
	$ret = [] ;
	$aoa = array ( [] ) ; // Array Of Arrays
	foreach ( $list AS $q ) {
		$q = trim ( strtoupper ( $q ) ) ;
		if ( !preg_match ( '/^Q\d+$/' , $q ) ) continue ;
		if ( count($aoa[count($aoa)-1]) >= 20 ) $aoa[] = [] ;
		$aoa[count($aoa)-1][] = $q ;
	}

	foreach ( $aoa AS $arr ) {
		if ( count($arr) == 0 ) continue ;
		$s = implode ( ' wd:' , $arr ) ;
		$sparql = "SELECT ?q ?doi { VALUES ?q { wd:$s } . ?q wdt:P356 ?doi }" ;
		enforceUncachedSPARQL ( $sparql ) ;
		$j = getSPARQL ( $sparql ) ;
		if ( !isset($j->results) or !isset($j->results->bindings) or count($j->results->bindings) == 0 ) continue ;
		foreach ( $j->results->bindings AS $b ) {
			$q = $b->q->value ;
			$doi = $b->doi->value ;
			$q = preg_replace ( '/^.+\//' , '' , $q ) ;
			$ret[$q] = $doi ;
		}
	}

	return $ret ;
}

*/
?>