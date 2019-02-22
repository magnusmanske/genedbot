<?PHP

#error_reporting(E_ERROR|E_CORE_ERROR|E_ALL|E_COMPILE_ERROR);
#ini_set('display_errors', 'On');

require_once ( __DIR__ . '/common.php' ) ;
require_once ( __DIR__ . '/wikidata.php' ) ;

class SourceMD {

	var $ids ;
	var $data ;
	var $props ;
	var $pubmed_months ;
	var $wd ;
	var $verbose = false ;
	var $these_props_only = array() ;
	var $issn = [] ;
	var $isbn = [] ;

	function SourceMD ( $id , $verbose = true ) {
		$this->verbose = $verbose ;
		
		// Sanity fixes
		$id = strtoupper ( trim ( preg_replace ( '/^doi:/i' , '' , trim($id) ) ) ) ;

		// Init
		$this->wd = new WikidataItemList ;
		$this->ids = array('orig'=>$id) ;
		$this->data = array() ;
		$this->pubmed_months = array (
			'Jan' => '01' ,
			'Feb' => '02' ,
			'Mar' => '03' ,
			'Apr' => '04' ,
			'May' => '05' ,
			'Jun' => '06' ,
			'Jul' => '07' ,
			'Aug' => '08' ,
			'Sep' => '09' ,
			'Oct' => '10' ,
			'Nov' => '11' ,
			'Dec' => '12' ,
		) ;
		foreach ( array_values($this->pubmed_months) AS $v ) $this->pubmed_months[$v] = $v ;
		$this->props = array(
			'pmid' => 'P698' ,
			'pmcid' => 'P932' ,
			'doi' => 'P356' ,
			'title' => 'P1476' ,
			'published in' => 'P1433' ,
			'original language' => 'P407' ,
			'volume' => 'P478' ,
			'page' => 'P304' ,
			'issue' => 'P433' ,
			'publication date' => 'P577' ,
			'main subject' => 'P921' ,
			'author' => 'P50' ,
			'short author' => 'P2093' ,
			'order' => 'P1545' ,
		) ;

		$success = false ;

		// Try to find matching IDs in catalogs
		if ( preg_match ( '/^Q\d+/i' , $id ) ) {
			$this->verbose = false ;
			$this->initFromItem ( $id ) ;
			foreach ( array ( 'pmcid' ) AS $k ) { // 'pmid' , 'pmcid' , 'doi'
				if ( !isset($this->ids[$k]) ) continue ;
				$v = $this->ids[$k] ;
				if ( $k == 'pmcid' ) $v = "PMC$v" ;
				$success = $success || $this->checkIDconv ( $v ) ;
			}


//			if ( isset($this->ids['pmcid']) and !isset($this->ids['pmid']) and !isset($this->ids['doi']) ) {
//				$this->checkIDconv ( $this->ids['pmcid'] ) ;
//			}
		} else {
			$success = $success || $this->checkIDconv ( $id ) ;
		}

//		if ( !$success ) return ; // No IDs found

		// Set IDs
		foreach ( array ( 'pmid' , 'pmcid' , 'doi' ) AS $k ) {
			if ( !isset($this->ids[$k]) ) continue ;
			$v = $this->ids[$k] ;
			if ( $k == 'pmcid' ) $v = preg_replace ( '/^PMC/' , '' , $v ) ;
			if ( $k == 'doi' ) $v = strtoupper ( $v ) ;
			$this->data[$this->props[$k]] = array ( $v , 'string' ) ;
		}

		// Dummy default
		$this->data['P31'] = array ( 'Q13442814' , 'item' ) ; // "scientific article"
		$this->authors = array() ;
		
		// Get data
		$this->loadPubmed () ;
		$this->loadDOI () ;
		
		// Check exists
		$dupes = array() ;
		foreach ( array ( 'pmid' , 'pmcid' , 'doi' ) AS $k ) {
			$p = $this->props[$k] ;
			if ( !isset($this->data[$p]) ) continue ;
			$v = $this->data[$p][0] ;
			if ( $k == 'doi' ) $v = strtoupper ( $v ) ;
			$exist = $this->getWikidataItemsByStringProp ( $p , $v ) ;
			foreach ( $exist as $q ) $dupes["Q$q"] = $q ;
		}
		
		$this->number_of_existing_items = count($dupes) ;
		
		if ( count($dupes) > 0 ) {
			$this->out ( "<p>Other sources with these identifiers exist:" ) ;
			foreach ( $dupes AS $q => $dummy ) {
				if ( !isset($this->existing_q) ) $this->existing_q = $q ;
				$this->out ( " <a href='//www.wikidata.org/wiki/$q' target='_blank'>$q</a>" ) ;
			}
			$this->out ( "</p>" ) ;

			$this->out ( "<p>Trying to update values of {$this->existing_q}; this will not change existing ones.</p>" ) ;
		}
		
		$this->checkAuthors() ;
		
//		$this->showQuickStatements() ;

//		print "<pre>" ; print_r ( $this->data ) ; print "</pre>" ;
	}
	
	function initFromItem ( $q ) {
		$this->existing_q = $q ;
		$this->wd->loadItem ( $this->existing_q ) ;
		$i = $this->wd->getItem ( $this->existing_q ) ;
		if ( !isset($i) ) return ;
		
		foreach ( array ( 'pmid' , 'pmcid' , 'doi' ) AS $k ) {
			$v = $i->getClaims ( $this->props[$k] ) ;
			if ( count($v) == 0 ) continue ;
			$v = $v[0]->mainsnak->datavalue->value ;
			if ( $k == 'doi' ) $v = strtoupper ( $v ) ;
			$this->ids[$k] = $v ;
		}
	}
	
	function invalidateExistingAuthors ( $claims ) {
		$so = $this->props['order'] ;
		foreach ( $claims AS $claim ) {
			if ( !isset ( $claim->qualifiers ) ) continue ;
			if ( !isset ( $claim->qualifiers->$so ) ) continue ;
			$quals = $claim->qualifiers->$so ;
			foreach ( $quals AS $qual ) {
				$num = $qual->datavalue->value ;
				if ( isset($this->authors[$num]) ) $this->authors[$num]->hadthat = true ;
			}
		}
	}
	
	function checkAuthors () {
//print "<pre>" ; print_r ( $this ) ; print "</pre>" ;
		if ( count ( $this->authors) == 0 ) return ;
	
		if ( isset($this->existing_q) ) {
			$this->wd->loadItem ( $this->existing_q ) ;
			$i = $this->wd->getItem ( $this->existing_q ) ;
			$c1 = $i->getClaims ( $this->props['author'] ) ;
			$c2 = $i->getClaims ( $this->props['short author'] ) ;
			$this->invalidateExistingAuthors ( $c1 ) ;
			$this->invalidateExistingAuthors ( $c2 ) ;
			
			// Special case: Single author, one author already in item...
			if ( count($this->authors) == 1 && count($c1)+count($c2) == 1 ) {
				$this->authors[1]->hadthat = true ;
			}
		}
	}

	function fixStringLengthAndHTML ( $s ) {
		$s = str_replace ( html_entity_decode('&#160;') , ' ' , $s ) ;
		$s = str_replace ( '&amp;' , '&' , $s ) ;
		$s = str_replace ( '&quot;' , '"' , $s ) ;
		$s = preg_replace ( '/<.+?>/' , ' ' , $s ) ;
		$s = preg_replace ( '/\s+/' , ' ' , $s ) ;
		$s = trim ( $s ) ;
		if ( strlen($s) > 250 ) $s = substr ( $s , 0 , 250 ) ;
		return $s ;
	}
	
	function getQuickStatementRow ( $p , $d ) {
		// Sanity filters
		if ( count($this->these_props_only)>0 and !in_array ( $p , $this->these_props_only ) ) return '' ;
		if ( $p == 'P304' and preg_match ( '/n\/a/i' , $d[0] ) ) return '' ;
		
		$q = 'LAST' ;
		if ( isset($this->existing_q) ) {
			$q = $this->existing_q ;
			$i = $this->wd->getItem($this->existing_q) ;
			if ( isset ( $i ) ) {
				if ( $i->hasClaims($p) ) return '' ; // Has that property
				if ( $d[1] == 'label' ) {
					if ( $i->getLabel($d[2]) == $d[0] ) return '' ; // Has that label
					$existing_label = $i->getLabel($d[2],true) ;
					if ( $existing_label != '' and $existing_label != $i->getQ() ) $d[1] = 'alias' ;
				}
			}
		}

		$fl = $this->fixStringLengthAndHTML($d[0]) ;
		if ( $d[1] == 'string' ) {
			if ( trim($d[0]) == '' ) return '' ;
			if ( $fl == '' ) return '' ; # Paranoia
			return "$q\t$p\t\"{$fl}\"" ;
		}
		if ( $d[1] == 'item' ) return "$q\t$p\t" . $d[0] ;
		if ( $d[1] == 'date' ) return "$q\t$p\t" . $d[0] . "/" . $d[2] ;
		
		if ( $fl == '' ) return '' ; # Paranoia
		if ( $d[1] == 'monolingual' ) return "$q\t$p\t" . $d[2] . ":\"" . $fl . "\"" ;
		if ( $d[1] == 'label' ) return "$q\tL" . $d[2] . "\t\"" . $fl . "\"" ;
		if ( $d[1] == 'alias' ) return "$q\tA" . $d[2] . "\t\"" . $fl . "\"" ;
		return '' ;
	}
	
	function generateQuickStatements () {
		$rows = array() ;
		if ( !isset($this->existing_q) ) $rows[] = "CREATE" ;
		
		foreach ( $this->data AS $prop => $d ) {
			if ( count($d) < 2 ) continue ;
			if ( $d[1] == 'array' ) {
				foreach ( $d[0] AS $d_sub ) {
					$row = $this->getQuickStatementRow($prop,$d_sub) ;
					if ( $row != '' ) $rows[] = $row ;
				}
			} else {
				$row = $this->getQuickStatementRow($prop,$d) ;
				if ( $row != '' ) $rows[] = $row ;
			}
		}


		$prop = $this->props['short author'] ;
		foreach ( $this->authors AS $a ) {
			if ( !isset($a) ) continue ;
			if ( isset($a->hadthat) and $a->hadthat ) continue ;
			$d = array ( $a->name , 'string' ) ;
			$row = $this->getQuickStatementRow($prop,$d) ;
			if ( trim($row) == '' ) continue ;
			$row .= "\t" . $this->props['order'] . "\t\"" . $a->order . "\"" ;
			$rows[] = $row ;
		}
		
		return $rows ;
	}
		
	function showQuickStatements () {
		$rows = $this->generateQuickStatements() ;
		if ( count($rows) == 0 ) {
			$this->out ( "<p>The item seems to be complete, nothing to update!</p>" ) ;
			return ;
		}
		
		$this->out ( "<p>You can create a new item, or update an existing one, for this source in QuickStatements:</p>" ) ;
		$this->out ( "<form class='form from-inline' action='https://tools.wmflabs.org/wikidata-todo/quick_statements.php' method='post'><textarea name='list' rows=15 style='width:100%'>" ) ;
		$this->out ( implode ( "\n" , $rows ) ) ;
		$this->out ( "</textarea><input type='submit' class='btn btn-primary' name='doit' value='Open in QuickStatements' /></form>" ) ;
	}
	
	function getWikidataLanguageCode ( $l ) {
		if ( !isset($l) or $l == '' ) return 'en' ;
		if ( $l == 'eng' ) return 'en' ;
		return '' ;
	}
	
	function getWikidataItemsByStringProp ( $prop , $s ) {
		if ( !isset($s) ) return array() ;
		$prop = 'P' . preg_replace('/\D/','',"$prop") ;
//		$query = "SELECT ?q { ?q wdt:$prop \"$s\" }" ;
		if ( strtoupper($s)==strtolower($s) ) $query = "SELECT ?q { ?q wdt:$prop \"$s\" }" ;
		else $query = "SELECT DISTINCT ?q { { ?q wdt:$prop \"".strtoupper($s)."\" } UNION { ?q wdt:$prop \"".strtolower($s)."\" } }" ;
//		else $query = "SELECT ?q { ?q wdt:$prop ?o FILTER (lcase(str(?o)) = lcase(\"$s\")) }" ;
//print "<pre>$query</pre>" ;
		$ret = getSPARQLitems ( $query ) ;
		return $ret ;
	}
	
	function getPubMedDate ( $d ) {
		if ( !isset($d) ) return array() ;
		if ( isset($d->Year) ) $year = $d->Year ;
		if ( isset($d->Month) ) $month = $this->pubmed_months[''.$d->Month] ;
		if ( isset($d->Day) ) $day = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$d->Day ) ;
		if ( !isset($year) ) return array() ;
		
		if ( isset($month) and isset($day) ) return array ( "+$year-$month-$day"."T00:00:00Z" , 'date' , '11' ) ;
		if ( isset($month) ) return array ( "+$year-$month-00T00:00:00Z" , 'date' , '10' ) ;
		if ( isset($year) ) return array ( "+$year-00-00T00:00:00Z" , 'date' , '9' ) ;
		return array() ;
	}
	
	function loadDOI () {
		if ( !isset($this->ids['doi']) ) return ;
		$id = $this->ids['doi'] ;
		$url = "https://api.crossref.org/v1/works/http://dx.doi.org/$id" ;
//if ( $this->verbose ) print "$url\n" ;
		$j = @file_get_contents ( $url ) ;
		if ( !isset($j) or $j == null or $j == '' ) {
			$this->out ( "<div style='font-weight:bold;'><a target='_blank' href='$url'>CrossRef lookup</a> has failed!</div>" ) ;
			return ;
		}
		$j = json_decode ( $j ) ;
		if ( !isset($j) or $j == null ) return ;
		if ( $j->status != 'ok' ) return ;
		if ( !isset($j->message) ) return ;
		
		// Title
		if ( isset($j->message->title) and count($j->message->title) > 0 and !isset($this->data[$this->props['title']]) ) {
			$lang = 'en' ; // Dummy
			$title = $j->message->title[0] ;
			$title = preg_replace ( '/\.$/' , '' , $title ) ;
			$this->data[$this->props['title']] = array ( $title , 'monolingual' , $lang ) ;
			$this->data['label'] = array ( $title , 'label' , $lang ) ;
		}
		
		// Issue/volume/page
		foreach ( array ( 'issue' , 'page' , 'volume' ) AS $k ) {
			if ( !isset($j->message->$k) ) continue ; // Not in dataset
			if ( isset($this->data[$this->props[$k]]) ) continue ; // Do not overwrite previous
			$s = ''.$j->message->$k ;
			if ( trim($s) == '' ) continue ;
			$this->data[$this->props[$k]] = array ( $s , 'string' ) ;
		}
		
		// Publication date
		$dp = 'date-parts' ;
		if ( !isset($this->data[$this->props['publication date']]) and isset($j->message->issued) and isset($j->message->issued->$dp) and count($j->message->issued->$dp) == 1 ) {
			$d = $j->message->issued->$dp ;
			$d = $d[0] ;
			if ( count($d) > 0 ) $year = $d[0] ;
			if ( count($d) > 1 ) $month = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$d[1] ) ;
			if ( count($d) > 2 ) $day = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$d[2] ) ;
			if ( isset($month) and isset($day) ) $this->data[$this->props['publication date']] = array ( "+$year-$month-$day"."T00:00:00Z" , 'date' , '11' ) ;
			else if ( isset($month) ) $this->data[$this->props['publication date']] = array ( "+$year-$month-00T00:00:00Z" , 'date' , '10' ) ;
			else if ( isset($year) ) $this->data[$this->props['publication date']] = array ( "+$year-00-00T00:00:00Z" , 'date' , '9' ) ;
		}
		
		// Subject
		if ( isset($j->message->subject) and count($j->message->subject) > 0 ) {
			$subjects = $j->message->subject ;
			if ( !isset($this->data[$this->props['main subject']]) ) $this->data[$this->props['main subject']] = array ( array() , 'array' ) ;
			foreach ( $subjects AS $s ) {
				$s = strtolower ( $s ) ;
				if ( $s == 'general' ) continue ;
				if ( isset($this->data[$this->props['main subject']][0][$s]) ) continue ;
				$topic_q = $this->getTopicQ ( $s ) ;
				if ( $topic_q == '' ) continue ;
				$this->data[$this->props['main subject']][0][$s] = array ( $topic_q , 'item' ) ;
			}
		}

		// Journal
		if ( isset($j->message->ISSN) ) {
			foreach ( $j->message->ISSN AS $issn ) {
				$this->issn[$issn] = (isset($j->message->{'container-title'}) and count($j->message->{'container-title'})>0) ? $j->message->{'container-title'}[0] : $issn ;
				$journal = $this->getWikidataItemsByStringProp ( 'P236' , $issn ) ;
				if ( count($journal) == 1 ) $this->data[$this->props['published in']] = array ( 'Q'.$journal[0] , 'item' ) ;
			}
		}

		// ISBN
		if ( isset($j->message->ISBN) ) {
			foreach ( $j->message->ISBN AS $isbn ) {
				$this->isbn[$isbn] = (isset($j->message->{'container-title'}) and count($j->message->{'container-title'})>0) ? $j->message->{'container-title'}[0] : $isbn ;
			}
		}

		// Authors (only if not set by others)
		if ( isset($j->message->author) and count($this->authors) == 0 ) {
			$count = 0 ;
			foreach ( $j->message->author AS $a ) {
				$name = '' ;
				if ( isset($a->family) ) $name = $a->family ;
				if ( isset($a->given) ) $name = $a->given . ' ' . $name ;

				$count++ ;
				$na = (object) array (
					'name' => $name ,
					'order' => $count
				) ;

				if ( isset($a->affiliation) and count($a->affiliation) > 0 ) {
					$na->affiliation = $a->affiliation[0] ;
				}
				
				$this->authors[$count] = $na ;
			}
		}
//		print "<pre>" ; print_r ( $this->authors ) ; print "</pre>" ;
	}

	function getTopicQ ( $s ) {
		$s = trim(strtolower($s)) ;
		$url = "https://www.wikidata.org/w/api.php?action=wbsearchentities&search=" . urlencode($s) . "&language=en&format=json" ;
		$j2 = json_decode ( file_get_contents ( $url ) ) ;
		$qs = array() ;
		foreach ( $j2->search AS $sr ) {
			if ( isset($sr->label) and strtolower($sr->label) == $s ) {
				// remove scientific journals
				$this->wd->loadItem ( $sr->id ) ;
				if ( !$this->wd->hasItem ( $sr->id ) ) continue ; // Paranoia
				$i3 = $this->wd->getItem ( $sr->id ) ;
				if ( $i3->hasTarget ( 'P31' , 'Q5633421' ) ) continue ;
				$qs[] = $sr->id ;
			}
		}
//		print "<pre>" ; print_r ( $qs ) ; print "</pre>" ;
		if ( count($qs) != 1 ) return '' ;
		return $qs[0] ;
	}
	
	function loadPubmed () {
		if ( !isset($this->ids['pmid']) ) return ;
		$id = $this->ids['pmid'] ;
		$url = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=Pubmed&id=$id&rettype=xml" ;
		$xml = @file_get_contents ( $url ) ;
		if ( !isset($xml) or $xml === null ) return ;
		try {
			$d = new SimpleXMLElement ( $xml ) ;
		} catch (Exception $e) {
			return ;
		}
		if ( !isset($d->PubmedArticle) ) return ;
		

		// Subjects
		if ( isset ( $d->PubmedArticle->MedlineCitation ) and isset ( $d->PubmedArticle->MedlineCitation->KeywordList ) ) {
			if ( isset ( $d->PubmedArticle->MedlineCitation->KeywordList->Keyword ) ) {
				if ( !isset($this->data[$this->props['main subject']]) ) $this->data[$this->props['main subject']] = array ( array() , 'array' ) ;
				foreach ( $d->PubmedArticle->MedlineCitation->KeywordList->Keyword AS $kw ) {
					if ( !isset($kw) ) continue ;
					$kw = ((array)$kw) ;
					if ( !isset($kw[0]) ) continue ;
					$topic_q = $this->getTopicQ ( $kw[0] ) ;
					if ( $topic_q == '' ) continue ;
					$this->data[$this->props['main subject']][0][$kw[0]] = array ( $topic_q , 'item' ) ;
				}
			}
		}
//		print "<pre>" ; print_r ( $this->data[$this->props['main subject']] ) ; print "</pre>" ;
		
		
		// Title (default or English only)
		$lang = $this->getWikidataLanguageCode ( $d->PubmedArticle->MedlineCitation->Article->Language ) ;
		if ( $lang != '' ) {
			$title = $d->PubmedArticle->MedlineCitation->Article->ArticleTitle ;
			$title = preg_replace ( '/\.$/' , '' , $title ) ;
			$this->data[$this->props['title']] = array ( $title , 'monolingual' , $lang ) ;
			$this->data['label'] = array ( $title , 'label' , $lang ) ;
			
		}
		
		// Orig lang
		if ( $lang == 'en' ) $this->data[$this->props['original language']] = array ( 'Q1860' , 'item' ) ;
		
		// Journal
		$journal = $this->getWikidataItemsByStringProp ( 'P236' , $d->PubmedArticle->MedlineCitation->Article->Journal->ISSN ) ;
//if ( isset($_REQUEST['testing']) ) { print "<pre>" ; print_r ( $d->PubmedArticle->MedlineCitation->Article->Journal ) ; print "</pre>" ; }
		if ( count($journal) == 1 ) $this->data[$this->props['published in']] = array ( 'Q'.$journal[0] , 'item' ) ;
		
		$ji = $d->PubmedArticle->MedlineCitation->Article->Journal->JournalIssue ;
		if ( isset($ji) ) {
			if ( isset($ji->Volume) ) $this->data[$this->props['volume']] = array ( ''.$ji->Volume , 'string' ) ;
			if ( isset($ji->Issue) ) $this->data[$this->props['issue']] = array ( ''.$ji->Issue , 'string' ) ;
			if ( isset($ji->PubDate) ) $this->data[$this->props['publication date']] = $this->getPubMedDate ( $ji->PubDate ) ;
		}
		
		if ( isset($d->PubmedArticle->MedlineCitation->Article->Pagination) and $d->PubmedArticle->MedlineCitation->Article->Pagination->MedlinePgn ) {
			$s = trim ( ''.$d->PubmedArticle->MedlineCitation->Article->Pagination->MedlinePgn ) ;
			if ( $s != '' ) $this->data[$this->props['page']] = array ( $s , 'string' ) ;
		}

		// Authors
		if ( isset ( $d->PubmedArticle->MedlineCitation->Article->AuthorList ) and isset ( $d->PubmedArticle->MedlineCitation->Article->AuthorList->Author ) ) {
			$authors = $d->PubmedArticle->MedlineCitation->Article->AuthorList->Author ;
			$count = 0 ;
			foreach ( $authors AS $a ) {
				$name = '' ;
				if ( isset($a->LastName) ) $name = $a->LastName ;
				if ( isset($a->ForeName) ) $name = $a->ForeName . ' ' . $name ;
				else if ( isset($a->Initials) ) $name = $name . ' ' . $a->Initials ;

				$count++ ;
				$na = (object) array (
					'name' => $name ,
					'order' => $count
				) ;

				if ( isset($a->AffiliationInfo) and isset($a->AffiliationInfo->Affiliation) ) {
					$na->affiliation = $a->AffiliationInfo->Affiliation ;
				}
				
				$this->authors[$count] = $na ;
			}
		}
		
//		print "<pre>" ; print_r ( $this->authors ) ; print "</pre>" ;
	}
	
	function checkIDconv ( $id ) {
		$url = "http://www.ncbi.nlm.nih.gov/pmc/utils/idconv/v1.0/?tool=my_tool&email=my_email@example.com&versions=no&format=json&ids=" . urlencode($id) ;
		$j = @file_get_contents ( $url ) ;
		if ( $j === false ) {
			print "Failed to load PubMed: $url\n" ;
			return ;
		}
		$j = json_decode ( $j ) ;
		if ( $j == null ) return ;
		if ( $j->status != 'ok' ) return ;
		if ( !isset($j->records) ) return ;
		if ( count($j->records) != 1 ) return ;
		foreach ( $j->records[0] AS $k => $v ) $this->ids[$k] = $v ;
	}
	
	function out ( $s ) {
		if ( !$this->verbose ) return ;
		print "$s\n" ;
	}
	
}


?>