<?PHP

require_once ( __DIR__ . '/ToolforgeCommon.php' ) ;
require_once ( __DIR__ . '/wikidata.php' ) ;


$fake_item_wil = new WikidataItemList ;

class BlankWikidataItem {

	public $object_list = ['labels','descriptions','aliases','claims','sitelinks'] ;
	public $j ;

	function __construct() {
		$this->j = (object) [] ;
		foreach ( $this->object_list AS $k ) {
			$this->j->$k = (object) [] ;
		}
	}

	public function getDatatype ( $property ) {
		global $fake_item_wil ;
		$property = 'P' . preg_replace ( '/\D/' , '' , $property ) ;
		$fake_item_wil->loadItem ( $property ) ;
		$i = $fake_item_wil->getItem ( $property ) ;
		if ( !isset($i) ) die ( "Can't get data type for non-existing property {$property}\n" ) ;
		if ( !isset($i->j->datatype) ) die ( "No datatype for {$property}:\n" . json_encode($i->j,JSON_PRETTY_PRINT) ) ;
		return $i->j->datatype ;
	}

	public function newItem ( $q ) {
		$q = trim(strtoupper($q)) ;
		$et = (preg_match('/^P\d+$/',$q)) ? 'property' : 'item' ;
		return (object) [ "value"=>(object)['entity-type'=>$et,'numeric-id'=>preg_replace('/\D/','',$q),'id'=>$q] , 'type'=>'wikibase-entityid' ] ;
	}

	public function newTime ( $time , $precision = -1 , $calendarmodel = 'Q1985727' ) {
		$orig_time = $time ;
		if ( preg_match ( '/^\d+$/' , $time ) ) { $time = "{$time}-01-01" ; if($precision==-1) $precision = 9 ; }
		if ( preg_match ( '/^\d+-\d{2}$/' , $time ) ) { $time = "{$time}-01" ; if($precision==-1) $precision = 10 ; }
		if ( preg_match ( '/^\d{1,4}-\d{2}-\d{2}$/' , $time ) ) { $time = "+{$time}T00:00:00Z" ; if($precision==-1) $precision = 11 ; }
		if ( $precision == -1 ) die ( "Cannot get precision from {$orig_time}\n" ) ;
		return (object) [ "value"=>(object)['time'=>$time,'timezone'=>0,'before'=>0,'after'=>0,'precision'=>$precision,'calendarmodel'=>"http://www.wikidata.org/entity/{$calendarmodel}"] , 'type'=>'time' ] ;
	}

	public function newCoord ( $lat , $lon , $globe = 'Q2' , $precision = 0.0002 , $altitude = null ) {
		return (object) [ "value"=>(object)['latitude'=>$lat*1,'longitude'=>$lon*1,'altitude'=>$altitude,'precision'=>$precision,'globe'=>"http://www.wikidata.org/entity/{$globe}"] , 'type'=>'globecoordinate' ] ;
	}

	public function newQuantity ( $amount , $unit = '1' ) {
		if ( preg_match ( '/^Q\d+$/' , $unit ) ) $unit = "http://www.wikidata.org/entity/{$unit}" ;
		if ( $amount*1 > 0 and $amount[0] != '+' ) $amount = "+{$amount}" ;
		return (object) [ "value"=>(object)['amount'=>$amount,'unit'=>"$unit"] , 'type'=>'quantity' ] ;
	}

	public function newString ( $s ) {
		return (object) [ "value"=>$s , 'type'=>'string' ] ;
	}

	public function newSnak ( $property , $datavalue , $snaktype = 'value' ) {
		$ret = (object) [
			'snaktype' => $snaktype ,
			'property' => $property ,
			'datatype' => $this->getDatatype ( $property )
		] ;
		if ( $snaktype == 'value' ) $ret->datavalue = $datavalue ;
		return $ret ;
	}

	private function addReferencesToClaim ( &$claim , $references ) {
		if ( count($references) > 0 ) {
			$claim->references = [] ;
			foreach ( $references AS $r ) {
				if ( is_array($r) ) $r = $this->formatReference ( $r ) ;
				$claim->references[] = $r ;
			}
		}
	}

	private function addQualifiersToClaim ( &$claim , $qualifiers ) {
		if ( is_object($qualifiers) and count($qualifiers) > 0 ) { # Qualifiers as object
			$claim->qualifiers = $qualifiers ;
		} else if ( is_array($qualifiers) and count($qualifiers) > 0 ) { # Qualifiers as array (easier to build)
			$claim->qualifiers = (object) [] ;
			foreach ( $qualifiers AS $qualifier ) {
				$p = $qualifier->property ;
				if ( !isset($claim->qualifiers->$p) ) $claim->qualifiers->$p = [] ;
				array_push ( $claim->qualifiers->$p , $qualifier ) ;
			}
		}
	}

	public function newSomevalue ( $property , $references = [] , $qualifiers = [] ) {
		$ret = (object) [
			'mainsnak' => $this->newSnak ( $property , '' , 'somevalue' ) ,
			'type' => 'statement' ,
			'rank' => 'normal'
		] ;
		$this->addReferencesToClaim ( $ret , $references ) ;
		$this->addQualifiersToClaim ( $ret , $qualifiers ) ;
		return $ret ;
	}

	public function newClaim ( $property , $datavalue , $references = [] , $qualifiers = [] ) {
		$ret = (object) [
			'mainsnak' => $this->newSnak ( $property , $datavalue ) ,
			'type' => 'statement' ,
			'rank' => 'normal'
		] ;
		$this->addReferencesToClaim ( $ret , $references ) ;
		$this->addQualifiersToClaim ( $ret , $qualifiers ) ;
		return $ret ;
	}

	public function getClaimByID ( $claim_id ) {
		foreach ( $this->j->claims AS $p => $claims ) {
			foreach ( $claims AS $claim ) {
				if ( $claim->id == $claim_id ) return $claim ;
			}
		}
	}

	public function addClaim ( $claim ) {
		$p = $claim->mainsnak->property ;
		if ( !isset($p) ) die ( "No property in claim ".json_encode($claim) ) ;
		if ( !isset($this->j->claims->$p) ) $this->j->claims->$p = [] ;
		array_push ( $this->j->claims->$p , $claim ) ;
	}

	public function addLabel ( $language , $value ) {
		$value = trim($value) ;
		if ( $value == '' ) return ;
		if ( is_array($language) ) {
			foreach ( $language AS $l ) $this->addLabel ( $l , $value ) ;
		} else {
			$this->j->labels->$language = (object) ['language'=>$language,'value'=>$value] ;
		}
	}

	public function addAlias ( $language , $value ) {
		$value = trim($value) ;
		if ( $value == '' ) return ;
		if ( isset($this->j->labels) and isset($this->j->labels->$language) and $this->j->labels->$language->value == $value ) return ; # Already the label
		if ( !isset($this->j->aliases->$language) ) $this->j->aliases->$language = [] ;
		foreach ( $this->j->aliases->$language AS $v ) {
			if ( $v->language == $language AND $v->value == $value ) return ; // Already have that
		}
		array_push ( $this->j->aliases->$language , (object) ['language'=>$language,'value'=>$value] ) ;
	}

	public function addDescription ( $language , $value ) {
		$value = trim($value) ;
		if ( $value == '' ) return ;
		if ( is_array($language) ) {
			foreach ( $language AS $l ) $this->addDescription ( $l , $value ) ;
		} else {
			$this->j->descriptions->$language = (object) ['language'=>$language,'value'=>$value] ;
		}
	}

	public function formatReference ( $references ) {
		$so = 'snaks-order' ;
		$ret = (object) ['snaks'=>(object)[],'snaks-order'=>[]] ;
		foreach ( $references AS $r ) {
			$p = $r->property ;
			if ( !in_array ( $p , $ret->$so ) ) array_push ( $ret->$so , $p ) ;
			if ( !isset($ret->snaks->$p) ) $ret->snaks->$p = [] ;
			array_push ( $ret->snaks->$p , $r ) ;
		}
		return $ret ;
	}

	public function stripClaim ( $claim ) {
		if ( isset($claim->id) ) unset ( $claim->id ) ;
		if ( isset($claim->mainsnak) and isset($claim->mainsnak->hash) ) unset ( $claim->mainsnak->hash ) ;
		if ( isset($claim->references) ) {
			foreach ( $claim->references AS $k => $v ) {
				if ( isset($v->hash) ) unset ( $claim->references[$k]->hash ) ;
			}
		}
		if ( isset($claim->qualifiers) ) {
			foreach ( $claim->qualifiers AS $p => $x ) {
				foreach ( $x AS $k => $v ) {
					if ( isset($v->hash) ) unset ( $v->hash ) ; # Maybe $claim->qualifiers->$k ...?
				}
			}
		}
		return $claim ;
	}

	private function snakMatches ( $snak1 , $snak2 ) {
		foreach ( ['snaktype','property','datatype'] AS $k ) {
			if ( isset($snak1->$k) and !isset($snak2->$k) ) return false ;
			if ( !isset($snak1->$k) and isset($snak2->$k) ) return false ;
			if ( $snak1->$k != $snak2->$k ) return false ;
		}
		if ( $snak1->snaktype == 'somevalue' ) return true ; # Both of them
		if ( !isset($snak1->datavalue) or !isset($snak2->datavalue) ) return false ; # Can't compare those?
		if ( $snak1->datavalue->type != $snak2->datavalue->type ) return false ;
		if ( is_string($snak1->datavalue->value) and is_string($snak2->datavalue->value) ) {
			return $snak1->datavalue->value == $snak2->datavalue->value ;
		} else if ( is_object($snak1->datavalue->value) and is_object($snak2->datavalue->value) ) {
			$keys = [] ;
			foreach ( $snak1->datavalue->value AS $k => $v ) $keys[$k] = $k ;
			foreach ( $snak2->datavalue->value AS $k => $v ) $keys[$k] = $k ;
			foreach ( $keys AS $k ) {
				if ( isset($snak1->datavalue->value->$k) and isset($snak2->datavalue->value->$k) and $snak1->datavalue->value->$k == $snak2->datavalue->value->$k ) continue ;
				if ( !isset($snak1->datavalue->type) ) die ( json_encode($snak1, JSON_PRETTY_PRINT) ) ;
				if ( $snak1->datavalue->type == 'globecoordinate' and $k == 'precision' ) continue ; # Meh
				return false ;
			}
			return true ;
		}
		return false ;
	}

	private function qualifiersMatch ( $claim1 , $claim2 , $options ) {
		if ( !isset($claim1->qualifiers) and !isset($claim2->qualifiers) ) return true ;
		if ( isset($claim1->mainsnak->property) and isset($options['ignore_qualifiers']) and in_array($claim1->mainsnak->property,$options['ignore_qualifiers']) ) {
			return true ;
		}
		foreach ( $claim1->qualifiers AS $k => $v1 ) {
			if ( !isset($claim2->qualifiers->$k) ) return false ;
			$v2 = $claim2->qualifiers->$k ;
			if ( count($v1) != count($v2) ) return false ;
			foreach ( $v1 AS $snak1 ) {
				$found = false ;
				foreach ( $v2 AS $snak2 ) {
					if ( !$this->snakMatches($snak1,$snak2) ) continue ;
					$found = true ;
					break ;
				}
				if ( !$found ) return false ;
			}
		}
		foreach ( $claim2->qualifiers AS $k => $v ) {
			if ( !isset($claim1->qualifiers->$k) ) return false ;
			# That's all. Equality already checked above.
		}
		return true ;
	}

	private function findReference ( $reference , $refence_list , $options ) {

		foreach ( $refence_list AS $r ) {
			$found = true ;
			foreach ( $reference->snaks AS $p => $snaks ) {
				if ( !isset($r->snaks->$p) ) {
					$found = false ;
					break ;
				}
				if ( isset($options['ref_skip_p']) and in_array ( $p , $options['ref_skip_p']) ) continue ;
				foreach ( $snaks AS $snak1 ) {
					$found_snak = false ;
					foreach ( $r->snaks->$p AS $snak2 ) {
						if ( !$this->snakMatches($snak1,$snak2) ) continue ;
						$found_snak = true ;
						break ;
					}
					if ( $found_snak ) continue ;
					$found = false ;
					break ;
				}
			}
			if ( $found ) return true ;
		}
		return false ;
	}

	private function referencesMatch ( $claim1 , $claim2 , $options ) {
		if ( !isset($claim1->references) and !isset($claim2->references) ) return true ;
		if ( isset($claim1->references) and !isset($claim2->references) ) return false ;
		if ( !isset($claim1->references) and isset($claim2->references) ) return false ;
		foreach ( $claim1->references AS $r ) {
			if ( !$this->findReference ( $r , $claim2->references , $options ) ) return false ;
		}
		foreach ( $claim2->references AS $r ) {
			if ( !$this->findReference ( $r , $claim1->references , $options ) ) return false ;
		}
		return true ;
	}

	private function doesItemHaveClaim ( $i , $claim , $options ) {
		$yes = (object) ['equal'=>true] ;
		$no = (object) ['equal'=>false] ;
		if ( !isset($i->j) ) return $no ; ;
		if ( !isset($i->j->claims) ) return $no ;
		$p = $claim->mainsnak->property ;
		if ( !isset($i->j->claims->$p) ) return $no ;
		foreach ( $i->j->claims->$p AS $c ) {
			if ( $claim->rank != $c->rank ) continue ;
			if ( $claim->type != $c->type ) continue ;
			if ( !$this->snakMatches($claim->mainsnak,$c->mainsnak) ) continue ;
			if ( !$this->qualifiersMatch($claim,$c,$options) ) continue ;
			if ( !$this->referencesMatch($claim,$c,$options) ) { # Identical claim/qualifiers but different references
				$no->diff = [ 'claim' => $c ] ;
				continue ;
			}
			return $yes ;
		}
		return $no ;
	}

	private function addAction ( &$ret , $type , $action , $options , $i ) {
		if ( is_array($action) ) $action = (object) $action ;
		if ( count((array)$action) == 0 ) return ; # Nothing to add
		if ( isset($options['validator']) ) { # Try final validator function
			if ( !($options['validator'] ( $type , $action , $i , $this ) ) ) return ;
		}
		if ( !isset($ret) ) $ret = (object) [] ;
		if ( $type == 'labels' || $type == 'descriptions' ) {
			if ( !isset($ret->$type) ) $ret->$type = (object) [] ;
			foreach ( $action AS $k => $v ) $ret->$type->$k = $v ;
		} else if ( $type == 'aliases' ) {
			if ( !isset($ret->$type) ) $ret->$type = [] ;
			foreach ( $action AS $k => $v ) array_push ( $ret->$type , $v ) ;
		} else {
			if ( !isset($ret->$type) ) $ret->$type = [] ;
			array_push ( $ret->$type , $action ) ;
		}
	}

	private function diffToItemClaims ( $i , &$ret , $options ) {
		$ignore_remove = [] ;
		foreach ( $this->j->claims AS $p => $claims ) {
			foreach ( $claims AS $claim ) {
				$res = $this->doesItemHaveClaim ( $i , $claim , $options ) ;
				if ( $res->equal ) continue ;
				if ( isset($res->diff ) ) {
					$claim_id = $res->diff['claim']->id ;
					$ignore_remove[] = $claim_id ;
					$o = $this->stripClaim($claim) ;
					$o->id = $claim_id ;
					$this->addAction ( $ret , 'claims' , $o , $options , $i ) ;
				} else {
					$this->addAction ( $ret , 'claims' , $this->stripClaim ( $claim ) , $options , $i ) ;
				}
			}
		}
		if ( isset($options['no_remove']) and is_bool($options['no_remove']) ) return ;
		if ( isset($i->j->claims) ) {
			foreach ( $i->j->claims AS $p => $claims ) {
				if ( isset($options['no_remove']) and is_array($options['no_remove']) and in_array($p,$options['no_remove']) ) continue ;
				if ( isset($options['remove_only']) and is_array($options['remove_only']) and !in_array($p,$options['remove_only']) ) continue ;
				foreach ( $claims AS $claim ) {
					if ( in_array ( $claim->id , $ignore_remove ) ) continue ;
					$res = $this->doesItemHaveClaim ( $this , $claim , $options ) ;
					if ( $res->equal ) continue ;
					$this->addAction ( $ret , 'claims' ,  ['id'=>$claim->id,'remove'=>''] , $options , $i ) ;
				}
			}
		}
	}

	private function diffToItemLabelsOrDescriptions ( $type , $i , &$ret , $options ) {
		if ( isset($this->j->$type) ) $x1 = $this->j->$type ;
		else $x1 = (object) [] ;
		if ( isset($i->j->$type) ) $x2 = $i->j->$type ;
		else $x2 = (object) [] ;

		$using_lang = [] ;
		foreach ( $x1 AS $lang => $x ) {
			if ( isset($options[$type]) ) {
				if ( isset($options[$type]['ignore']) and in_array($lang,$options[$type]['ignore']) ) continue ;
				if ( isset($options[$type]['ignore_except']) and !in_array($lang,$options[$type]['ignore_except']) ) continue ;
			}
			if ( !isset($x2->$lang) ) {
				$this->addAction ( $ret , $type , [$lang => (object) ['language'=>$lang,'value'=>$x->value]] , $options , $i ) ;
				$using_lang[$lang] = true ;
			} else if ( isset($x2->$lang) and $x2->$lang->value != $x->value ) {
				$this->addAction ( $ret , $type , [$lang => (object) ['language'=>$lang,'value'=>$x->value]] , $options , $i ) ;
				$using_lang[$lang] = true ;
			}
		}

		foreach ( $x2 AS $lang => $x ) {
			if ( isset($using_lang[$lang]) ) continue ;
			if ( isset($options[$type]) ) {
				if ( isset($options[$type]['ignore']) and in_array($lang,$options[$type]['ignore']) ) continue ;
				if ( isset($options[$type]['ignore_except']) and !in_array($lang,$options[$type]['ignore_except']) ) continue ;
			}
			if ( !isset($x1->$lang) ) {
				if ( isset($options[$type]['remove']) and !$options[$type]['remove'] ) continue ;
				$this->addAction ( $ret , $type , [$lang => (object) ['language'=>$lang,'value'=>$x->value,'remove'=>'']] , $options , $i ) ;
			}
		}
	}

	function diffLanguageAliases ( $lang , $a1 , $a2 , &$ret , $options , $i , $removing = false ) {
		$l1 = [] ;
		$l2 = [] ;
		foreach ( $a1 AS $av ) $l1[] = $av->value ;
		foreach ( $a2 AS $av ) $l2[] = $av->value ;
		sort ( $l1 ) ;
		sort ( $l2 ) ;
		if ( json_encode($l1) == json_encode($l2) ) return ;

		foreach ( $a1 AS $a1v ) {
			$found = false ;
			foreach ( $a2 AS $a2v ) {
				if ( $a1v->value == $a2v->value ) $found = true ;
			}
			if ( $found ) continue ;
			$o = ['language'=>$lang,'value'=>$a1v->value] ;
			if ( $removing ) $o['remove'] = '' ;
			$this->addAction ( $ret , 'aliases' , [$lang => (object) $o] , $options , $i ) ;
		}
	}

	function diffToItemAliases ( $i , &$ret , $options ) {
		if ( isset($this->j->aliases) ) $x1 = $this->j->aliases ;
		else $x1 = (object) [] ;
		if ( isset($i->j->aliases) ) $x2 = $i->j->aliases ;
		else $x2 = (object) [] ;

		foreach ( $x1 AS $lang => $a1 ) {
			if ( isset($options['aliases']['ignore']) and in_array($lang,$options['aliases']['ignore']) ) continue ;
			if ( isset($options['aliases']['ignore_except']) and !in_array($lang,$options['aliases']['ignore_except']) ) continue ;
			$a2 = [] ;
			if ( isset($x2->$lang) ) $a2 = $x2->$lang ;
			$this->diffLanguageAliases ( $lang , $a1 , $a2 , $ret , $options , $i , false ) ;
		}

		foreach ( $x2 AS $lang => $a1 ) {
			if ( isset($options['aliases']['remove']) and !$options['aliases']['remove'] ) continue ;
			if ( isset($options['aliases']['ignore']) and in_array($lang,$options['aliases']['ignore']) ) continue ;
			if ( isset($options['aliases']['ignore_except']) and !in_array($lang,$options['aliases']['ignore_except']) ) continue ;
			$a2 = [] ;
			if ( isset($x1->$lang) ) $a2 = $x1->$lang ;
			$this->diffLanguageAliases ( $lang , $a1 , $a2 , $ret , $options , $i , true ) ;
		}
	}

	/*
	options:
	- validator : function ( $type , $action ,  &$old_item  , &$new_item ) # Retunrs true or false
	- no_remove : [bool] # If true, will not remove anything
	              [P123,...] # Will not remove any claims with these properties, where appropriate
	- remove_only [P123,...] # Will only remove claims with these properties, where appropriate
	- ref_skip_p : ['P123',...] # Array of properties to ignore when comparing references. Usually P813 (date set)
	- labels/descriptions/aliases : [ 'ignore'=>['en',...] , 'ignore_except'=>['en',...] , 'remove' => false ] # ignores (or ignores all except) some languages, suppresses removal
	*/
	public function diffToItem ( $i , $options = [] ) {
		$ret = (object)[] ;
		$this->diffToItemClaims ( $i , $ret , $options ) ;
		$this->diffToItemLabelsOrDescriptions ( 'labels' , $i , $ret , $options ) ;
		$this->diffToItemLabelsOrDescriptions ( 'descriptions' , $i , $ret , $options ) ;
		$this->diffToItemAliases ( $i , $ret , $options ) ;
		# TODO sitelinks
		return $ret ;
	}

	public function today () {
		$today = date('Y-m-d') ;
		return $this->newTime ( $today ) ;
	}

} ;

?>