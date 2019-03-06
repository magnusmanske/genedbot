<?php

require_once ( __DIR__ . '/gff.php' ) ;
require_once ( __DIR__ . '/external/itemdiff.php' ) ;
require_once ( __DIR__ . '/external/quickstatements.php' ) ;
require_once ( __DIR__ . '/external/orcid_shared.php' ) ;

$qs = '' ;

class GFF2WD {
	public $load_orth_data = true ; # Set to false for debugging => faster
	var $use_local_data_files = false ;
	var $tfc , $wil ;
	var $gffj ;
	var $go_annotation = [] ;
	var $genes = [] ;
	var $genedb2q = [] ;
	var $protein_genedb2q = [] ;
	var $orth_genedb2q = [] ;
	var $orth_genedb2q_taxon = [] ;
	public $qs ;
	var $go_term_cache ;
	var $aspects = [ 'P' => 'P682' , 'F' => 'P680' , 'C' => 'P681' ] ;
	var $tmhmm_q = 'Q61895944' ;
	var $evidence_codes = [] ;
	var $sparql_result_cache = [] ;
	var $paper_editor ;
	var $other_types = [] ;
	var $alternate_gene_subclasses = [
		'tRNA' => 'Q201448' ,
		'rRNA' => 'Q215980' ,
		'pseudogene' => 'Q277338' ,
		'snoRNA' => 'Q284416' ,
		'ncRNA' => 'Q427087' ,
		'snRNA' => 'Q284578' ,
	] ;

	function __construct () {
		global $qs ;
		$this->tfc = new ToolforgeCommon ( 'genedb' ) ;
#		$this->tfc->log_sparql_requests = true ; # TESTING
		$this->qs = $this->tfc->getQS ( 'genedb' , __DIR__. '/bot.ini' , true ) ;
		$this->qs->generateAndUseTemporaryBatchID() ;
		$qs = $this->qs ;
		$this->wil = new WikidataItemList () ;
		$this->paper_editor = new PaperEditor ( $this->tfc , $this->qs , $this->wil ) ;
	}

	function getSpeciesQ () {
		return $this->gffj->wikidata_id ;
	}

	function createGenomicAssemblyForSpecies ( $species_q ) {
		$this->wil->loadItems ( [$species_q] ) ;
		$i = $this->wil->getItem ( $species_q ) ;
		if ( !isset($i) ) die ( "Cannot get item {$species_q} for species\n" ) ;
		$taxon_name = $i->getFirstString('P225') ;
		if ( $taxon_name == '' ) die ( "No P225 in {$species_q}\n" ) ;
		$commands = [] ;
		$commands[] = 'CREATE' ;
		$commands[] = "LAST\tLen\t\"{$taxon_name} reference genome\"" ;
		$commands[] = "LAST\tP279\tQ7307127" ; # Reference genome
		$commands[] = "LAST\tP703\t{$species_q}" ; # Found in species
		$q = $this->tfc->runCommandsQS ( $commands , $this->qs ) ;
		if ( !isset($q) or $q == '' ) die ( "Could not create item for reference genome for species '{$species_q}'\n" ) ;
		return $q ;
	}

	function getGenomicAssemblyForSpecies ( $species_q ) {
		$sparql = "SELECT ?q { ?q wdt:P279 wd:Q7307127 ; wdt:P703 wd:{$species_q} }" ;
		$items = $this->tfc->getSPARQLitems ( $sparql ) ;
		if ( count($items) > 1 ) { # Multiple genomic assemblies for that species, use the numerically largest ~ latest one
			$qnums = [] ;
			foreach ( $items AS $q ) $qnums[] = preg_replace('/\D/','',$q)*1 ;
			sort ( $qnums , SORT_NUMERIC ) ;
			return 'Q'.array_pop($qnums) ;
		}
		if ( count($items) == 0 ) { # No reference genome exists, create one
			return $this->createGenomicAssemblyForSpecies ( $this->getSpeciesQ() ) ;
		} else { # One reference genome exists, use that one
			return $items[0] ;
		}
		die ( "Can't find genomixc assembly for {$species_q}\n" ) ;
	}

	function ensureConfigComplete () {
		$species_q = $this->getSpeciesQ() ;
		if ( !isset($species_q) or null === $species_q ) {
			die ( "No species item\n" ) ;
		}
		if ( !isset($this->gffj->genomic_assembly) ) {
			$this->gffj->genomic_assembly = $this->getGenomicAssemblyForSpecies ( $species_q ) ;
		}
	}

	function init ( $genedb_id = '' ) {
		$this->ensureConfigComplete() ;
		$this->loadBasicItems() ;
		$this->loadGAF() ;
		$this->loadGFF() ;
		$this->run($genedb_id) ;
	}

	function computeFilenameGFF () {
		if ( $this->use_local_data_files ) {
			$gff_filename = __DIR__ . '/data/gff/'.$this->gffj->file_root.'.gff3.gz' ;
			if ( !file_exists($gff_filename) ) die ( "No GFF file: {$gff_filename}\n") ;
			return $gff_filename ;
		} else { # DEFAULT: FTP
			$root = $this->gffj->file_root ;
			return "ftp://ftp.sanger.ac.uk/pub/genedb/releases/latest/{$root}/{$root}.gff.gz" ;
#			return "ftp://ftp.sanger.ac.uk/pub/genedb/apollo_releases/latest/" . $this->gffj->file_root.'.gff3.gz' ; ;
		}
	}

	function computeFilenameGAF () {
		if ( $this->use_local_data_files ) {
			$ftp_root = 'ftp.sanger.ac.uk/pub/genedb/releases/latest/' ;
			$gaf_filename = __DIR__ . '/data/gaf/'.$ftp_root.'/'.$this->gffj->file_root.'/'.$this->gffj->file_root.'.gaf.gz' ;
			if ( !file_exists($gaf_filename) ) die ( "No GAF file: {$gaf_filename}\n") ;
			return $gaf_filename ;
		} else { # DEFAULT: FTP
			$root = $this->gffj->file_root ;
			return "ftp://ftp.sanger.ac.uk/pub/genedb/releases/latest/{$root}/{$root}.gaf" ; # .gz
#			return "ftp://ftp.sanger.ac.uk/pub/genedb/releases/latest/" . $this->gffj->file_root.'/'.$this->gffj->file_root.'.gaf.gz' ;
		}
	}


	function getQforChromosome ( $chr ) {
		if ( isset($this->gffj->chr2q[$chr]) ) return $this->gffj->chr2q[$chr] ;

		$commands = [] ;
		$commands[] = 'CREATE' ;
		$commands[] = "LAST\tLen\t\"{$chr}\"" ;
		$commands[] = "LAST\tP31\tQ37748" ; # Chromosome
		$commands[] = "LAST\tP703\t" . $this->getSpeciesQ() ;
		$q = $this->tfc->runCommandsQS ( $commands , $this->qs ) ;
		if ( !isset($q) or $q == '' ) die ( "Could not create item for chromosome '{$chr}'\n" ) ;
		$this->gffj->chr2q[$chr] = $q ;
		return $q ;
	}

	function getParentTaxon ( $q ) {
		$i = $this->wil->getItem ( $q ) ;
		if ( !isset($i) ) return ;
		$claims = $i->getClaims ( 'P171' ) ;
		if ( count($claims) != 1 ) return ;
		return $i->getTarget ( $claims[0] ) ;
	}

	function loadBasicItems () {
		# Load basic items (species, chromosomes)
		$items = $this->tfc->getSPARQLitems ( "SELECT ?q { ?q wdt:P31 wd:Q37748 ; wdt:P703 wd:" . $this->getSpeciesQ() . " }" ) ;
		$items[] = $this->getSpeciesQ() ;
		$items[] = $this->tmhmm_q ;
		$this->wil->loadItems ( $items ) ;
		$this->gffj->chr2q = [] ;
		foreach ( $items AS $q ) {
			$i = $this->wil->getItem ( $q ) ;
			if ( !isset($i) ) continue ;
			if ( !$i->hasTarget ( 'P31' , 'Q37748' ) ) continue ;
			$l = $i->getLabel ( 'en' , true ) ;
			$this->gffj->chr2q[$l] = $q ;
		}

		# Parent taxon? Used for rewriting species => strain, can be deactivated after
		$parent_q = $this->getParentTaxon ( $this->getSpeciesQ() ) ;
		$species_list = [ $this->getSpeciesQ() ]  ;
		if ( isset($parent_q) ) $species_list[] = $parent_q ;
		$species_list = " VALUES ?species { wd:" . implode(' wd:', $species_list) . " } " ;

		# All genes for this species with GeneDB ID
		$gene_p31 = "VALUES ?gene_types { wd:Q7187 wd:" . implode ( ' wd:' , $this->alternate_gene_subclasses ) . " }" ;
		$sparql = "SELECT DISTINCT ?q ?genedb { {$species_list} . {$gene_p31} . ?q wdt:P31 ?gene_types  ; wdt:P703 ?species ; wdt:P3382 ?genedb }" ;
		$j = $this->tfc->getSPARQL ( $sparql ) ;
//		if ( !isset($j->results) or !isset($j->results->bindings) or count($j->results->bindings) == 0 ) die ( "SPARQL loading of genes failed\n" ) ;
		foreach ( $j->results->bindings AS $v ) {
			$q = $this->tfc->parseItemFromURL ( $v->q->value ) ;
			if ( !$this->isRealItem($q) ) continue ;
			$genedb_id = $v->genedb->value ;
			if ( isset($this->genedb2q[$genedb_id]) ) die ( "Double genedb {$genedb_id} in species " . $this->getSpeciesQ() . " for gene {$q} and {$this->genedb2q[$genedb_id]}\n" ) ;
			$this->genedb2q[$genedb_id] = $q ;
		}

		# All protein for this species with GeneDB ID
		$sparql = "SELECT DISTINCT ?q ?genedb { {$species_list} ?q wdt:P31 wd:Q8054 ; wdt:P703 ?species ; wdt:P3382 ?genedb }" ;
		$j = $this->tfc->getSPARQL ( $sparql ) ;
//		if ( !isset($j->results) or !isset($j->results->bindings) ) die ( "SPARQL loading of proteins failed\n" ) ;
		foreach ( $j->results->bindings AS $v ) {
			$q = $this->tfc->parseItemFromURL ( $v->q->value ) ;
			if ( !$this->isRealItem($q) ) continue ;
			$genedb_id = $v->genedb->value ;
			if ( isset($this->protein_genedb2q[$genedb_id]) ) die ( "Double genedb {$genedb_id} in species " . $this->getSpeciesQ() . " for protein {$q} and {$this->protein_genedb2q[$genedb_id]}\n" ) ;
			$this->protein_genedb2q[$genedb_id] = $q ;
		}

		# Evidence codes
		$sparql = 'SELECT DISTINCT ?q ?qLabel { ?q wdt:P31 wd:Q23173209 SERVICE wikibase:label { bd:serviceParam wikibase:language "en" } }' ;
		$j = $this->tfc->getSPARQL ( $sparql ) ;
		foreach ( $j->results->bindings AS $b ) {
			$label = $b->qLabel->value ;
			$eq = $this->tfc->parseItemFromURL ( $b->q->value ) ;
			$this->evidence_codes[$label] = $eq ;
		}

		$to_load = array_merge (
			array_values($this->genedb2q),
			array_values($this->protein_genedb2q),
			array_values($this->evidence_codes)
		) ;
		$this->wil->loadItems ( $to_load ) ; # TODO turn on
	}

	function isRealItem ( $q ) { # Returns false if it's a redirect
		return true ; # SHORTCUTTING
	}

	function loadGAF () {
		$gaf_filename = $this->computeFilenameGAF() ;
		$gaf = new GAF ( $gaf_filename ) ;
		$cnt = 0 ;
		while ( $r = $gaf->nextEntry() ) {
			if ( isset($r['header'] ) ) continue ;
			$this->go_annotation[$r['id']][] = $r ;
			$cnt++ ;
		}
		if ( $cnt == 0 ) die ( "No/empty GAF file: {$gaf_filename}\n" ) ;
	}

	function loadGFF () {
		$gff_filename = $this->computeFilenameGFF() ;
		$gff = new GFF ( $gff_filename ) ;
		$orth_ids = [] ;
		$cnt = 0 ;
		while ( $r = $gff->nextEntry() ) {
			if ( isset($r['comment']) ) continue ;
			$cnt++ ;
			if ( !in_array ( $r['type'] , ['gene','mRNA','pseudogene'] ) ) {
				if ( isset($r['attributes']) and isset($r['attributes']['ID']) ) {
					$id = $r['attributes']['ID'] ;
					$id = preg_replace ( '/:.*$/' , '' , $id ) ;
					$id = preg_replace ( '/\.\d$/' , '' , $id ) ;
					$this->other_types[$r['type']][$id] = 1 ;
					if ( isset($r['attributes']['Parent']) ) {
						$parent_id = $r['attributes']['Parent'] ;
						$this->other_types[$r['type']][$parent_id] = 1 ;
					}

				}
				continue ;
			}
			if ( isset($r['attributes']['Parent']) ) $this->genes[$r['attributes']['Parent']][$r['type']][] = $r ;
			else $this->genes[$r['attributes']['ID']][$r['type']] = $r ;
			if ( isset($r['attributes']['orthologous_to']) ) {
				foreach ( $r['attributes']['orthologous_to'] AS $orth ) {
					if ( !preg_match ( '/^\s*\S+?:(\S+)/' , $orth , $m ) ) continue ;
					if ( $this->load_orth_data ) $orth_ids[$m[1]] = 1 ;
				}
			}
		}
		if ( $cnt == 0 ) die ( "No/empty GFF file: {$gff_filename}\n" ) ;

		# Orthologs cache
		$orth_chunks = array_chunk ( array_keys($orth_ids) , 100 ) ;
		foreach ( $orth_chunks AS $chunk ) {
			$sparql = "SELECT ?q ?genedb ?taxon { VALUES ?genedb { '" . implode("' '",$chunk) . "' } . ?q wdt:P3382 ?genedb ; wdt:P703 ?taxon }" ;
			$j = $this->tfc->getSPARQL ( $sparql ) ;
			foreach ( $j->results->bindings AS $v ) {
				$q = $this->tfc->parseItemFromURL ( $v->q->value ) ;
				$q_taxon = $this->tfc->parseItemFromURL ( $v->taxon->value ) ;
				$genedb = $v->genedb->value ;
				$this->orth_genedb2q[$genedb] = $q ;
				$this->orth_genedb2q_taxon[$genedb] = $q_taxon ;
			}
		}

	}

	private function fixAliasName ( $s ) {
		$s = preg_replace ( '/;.*$/' , '' , $s ) ;
		return $s ;
	}

	public function createOrAmendGeneItem ( $g ) {
		$types = [] ;
		if ( isset($g['gene']) ) {
			$types = ['gene','Q7187'] ;
		} else if ( isset($g['pseudogene']) ) {
			$types = ['pseudogene','Q277338'] ;
		} else {
			print "No gene:\n".json_encode($g)."\n" ;
			return ;
		}
		$gene = $g[$types[0]] ;
		if ( !isset($gene['attributes']) ) {
			print "No attributes for gene\n".json_encode($g)."\n" ;
			return ;
		}
		$genedb_id = $gene['attributes']['ID'] ;

		if ( isset($this->genedb2q[$genedb_id]) ) {
			$gene_q = $this->genedb2q[$genedb_id] ;
			$this->wil->loadItems ( [$gene_q] ) ;
			$item_to_diff = $this->wil->getItem ( $gene_q ) ;
		} else {
			$item_to_diff = new BlankWikidataItem ;
			$gene_q = 'LAST' ;
		}

		$chr_q = $this->getQforChromosome ( $gene['seqid'] ) ; #$this->gffj->chr2q[$gene['seqid']] ;
		$gene_i = new BlankWikidataItem ;

		# Label and aliases, en only

		if ( isset($gene['attributes']['Name']) ) {
			$gene_i->addLabel ( 'en' , $gene['attributes']['Name'] ) ;
			$gene_i->addAlias ( 'en' , $genedb_id ) ;
		} else {
			$gene_i->addLabel ( 'en' , $genedb_id ) ;
		}

		if ( isset($gene['attributes']['previous_systematic_id']) ) {
			foreach ( $gene['attributes']['previous_systematic_id'] AS $v ) {
				foreach ( explode(',',$this->fixAliasName($v)) AS $v2 ) $gene_i->addAlias ( 'en' , $v2 ) ;
			}
		}
		if ( isset($gene['attributes']['synonym']) ) {
			foreach ( explode(',',$this->fixAliasName($gene['attributes']['synonym'])) AS $v2 ) $gene_i->addAlias ( 'en' , $v2 ) ;
		}

		# Statements
		$refs = [
			$gene_i->newSnak ( 'P248' , $gene_i->newItem('Q5531047') ) ,
			$gene_i->newSnak ( 'P813' , $gene_i->today() )
		] ;
		$ga_quals = [
			$gene_i->newSnak ( 'P659' , $gene_i->newItem($this->gffj->genomic_assembly) ) ,
			$gene_i->newSnak ( 'P1057' , $gene_i->newItem($chr_q) )
		] ;
		$strand_q = $gene['strand'] == '+' ? 'Q22809680' : 'Q22809711' ;

		$gene_i->addClaim ( $gene_i->newClaim('P31',$gene_i->newItem($types[1]) , [$refs] ) ) ; # Instance of
		$gene_i->addClaim ( $gene_i->newClaim('P703',$gene_i->newItem($this->getSpeciesQ()) , [$refs] ) ) ; # Found in:Species
		$gene_i->addClaim ( $gene_i->newClaim('P1057',$gene_i->newItem($chr_q) , [$refs] ) ) ; # Chromosome
		$gene_i->addClaim ( $gene_i->newClaim('P2548',$gene_i->newItem($strand_q) , [$refs] , $ga_quals ) ) ; # Strand

		$gene_i->addClaim ( $gene_i->newClaim('P3382',$gene_i->newString($genedb_id) , [$refs] ) ) ; # GeneDB ID
		$gene_i->addClaim ( $gene_i->newClaim('P644',$gene_i->newString($gene['start']) , [$refs] , $ga_quals ) ) ; # Genomic start
		$gene_i->addClaim ( $gene_i->newClaim('P645',$gene_i->newString($gene['end']) , [$refs] , $ga_quals ) ) ; # Genomic end
		

		# Do protein
		$protein_qs = $this->createOrAmendProteinItems ( $g , $gene_q ) ;
		if ( count($protein_qs) > 0 ) { # Encodes
			$gene_i->addClaim ( $gene_i->newClaim('P279',$gene_i->newItem('Q20747295') , [$refs] ) ) ; # Subclass of:protein-coding gene
			foreach ( $protein_qs AS $protein_q ) {
				$gene_i->addClaim ( $gene_i->newClaim('P688',$gene_i->newItem($protein_q) , [$refs] ) ) ; # Encodes:Protein
			}
		} else if ( $types[0] != 'gene' ) {
			$gene_i->addClaim ( $gene_i->newClaim('P279',$gene_i->newItem($types[1]) , [$refs] ) ) ;
		} else { # tRNA/rRNA/pseudogene etc.
			$found = false ;
			foreach ( $this->alternate_gene_subclasses AS $k => $v ) {
				if ( !isset($this->other_types[$k]) ) continue ;
				if ( !isset($this->other_types[$k][$genedb_id]) ) continue ;
				$gene_i->addClaim ( $gene_i->newClaim('P279',$gene_i->newItem($v) , [$refs] ) ) ;
				$found = true ;
				break ;
			}
			if ( !$found ) {
				print "No subclass found for {$genedb_id}\n" ;
			}
		}

		# Orthologs
		if ( count($protein_qs) > 0 ) {
			foreach ( $g['mRNA'] AS $protein ) {
				if ( !isset($protein['attributes']['orthologous_to']) ) continue ;
				foreach ( $protein['attributes']['orthologous_to'] AS $orth ) {
					if ( !preg_match ( '/^\s*(\S)+?:(\S+)/' , $orth , $m ) ) continue ;
					$species = $m[1] ; # Not used
					$genedb_orth = $m[2] ;
					if ( !isset($this->orth_genedb2q[$genedb_orth]) ) continue ;
					if ( !isset($this->orth_genedb2q_taxon[$genedb_orth]) ) continue ;
					$orth_q = $this->orth_genedb2q[$genedb_orth] ;
					$orth_q_taxon = $this->orth_genedb2q_taxon[$genedb_orth] ;
					$gene_i->addClaim ( $gene_i->newClaim('P684',$gene_i->newItem($orth_q) , [$refs] , [ $gene_i->newSnak ( 'P703' , $gene_i->newItem($orth_q_taxon) ) ] ) ) ; # Encodes:Protein
				}
			}
		}


		$options = [
			'ref_skip_p'=>['P813'] ,
			'labels' => [ 'ignore_except'=>['en'] ] ,
			'descriptions' => [ 'ignore_except'=>['en'] ] ,
			'aliases' => [ 'ignore_except'=>['en'] ] ,
			'remove_only' => [
				'P279', // Subclass of (mostly to remove protein-coding gene)
				'P703', // Found in taxon
				'P680', // Molecular function
				'P681', // Cell component
				'P682', // Biological process
				'P684', // Ortholog
				'P1057', // Chromosome
				'P2548', // Strand orientation
				'P644', // Genomic start
				'P645' // Genomic end
			]
		] ;
		$diff = $gene_i->diffToItem ( $item_to_diff , $options ) ;

		$params = (object) [
			'action' => 'wbeditentity' ,
			'data' => json_encode($diff) ,
			'summary' => 'Syncing to GeneDB (V2) ' . $this->qs->getTemporaryBatchSummary() ,
			'bot' => 1
		] ;
		if ( $gene_q == 'LAST' ) $params->new = 'item' ;
		else $params->id = $gene_q ;

		# Create or amend gene item
		$new_gene_q = '' ;
		if ( $params->data == '{}' ) { # No changes
			if ( $gene_q == 'LAST' ) die ( "Cannot create empty gene for gene {$genedb_id}\n" ) ; # Paranoia
		} else {
			if ( !$this->qs->runBotAction ( $params ) ) {
				print_r ( $params ) ;
				print "Failed trying to edit gene '{$genedb_id}': '{$oa->error}' / ".json_encode($qs->last_res)."\n" ;
				return ;
			}
			if ( $gene_q == 'LAST' ) {
				$new_gene_q = $qs->last_res->entity->id ;
				$this->genedb2q[$genedb_id] = $new_gene_q ;
				$this->wil->updateItem ( $new_gene_q ) ; # Is new
			} else {
				$this->wil->updateItem ( $gene_q ) ; # Has changed
			}
		}

		if ( $gene_q == 'LAST' and $this->isItem($new_gene_q) ) $gene_q = $new_gene_q ;
		if ( !$this->isItem ( $gene_q ) ) return ; # Paranoia

		# Ensure gene <=> protein links
		$to_load = $protein_qs ;
		$to_load[] = $gene_q ;
		$this->wil->loadItems ( $to_load ) ;
		foreach ( $protein_qs AS $protein_q ) {
			$this->linkProteinToGene ( $gene_q , $protein_q ) ;
		}
	}

	function isItem ( $q ) {
		if ( !isset($q) or $q === false or $q == null ) return false ;
		return preg_match ( '/^Q\d+$/' , $q ) ;
	}

	function linkProteinToGene ( $gene_q , $protein_q ) {
		if ( !$this->isItem ( $gene_q ) ) return ; # Paranoia
		if ( !$this->isItem ( $protein_q ) ) return ; # Paranoia
		$this->wil->loadItems ( [ $gene_q , $protein_q ] ) ;
		$gene = $this->wil->getItem ( $gene_q ) ;
		$protein = $this->wil->getItem ( $protein_q ) ;
		if ( !isset($gene) or !isset($protein) ) return ; # Paranoia

		$commands = [] ;
		if ( !$gene->hasTarget ( 'P688' , $protein_q ) ) { # Link gene to protein
			$commands[] = "{$gene_q}\tP688\t{$protein_q}" ; # Gene:encodes:Protein
		}

		if ( !$protein->hasTarget ( 'P702' , $gene_q ) ) { # Link protein to gene
			$commands[] = "{$protein_q}\tP702\t{$gene_q}" ; # Protein:encoded by:gene
		}

		$this->addSourceToCommands ( $commands ) ;
		$this->tfc->runCommandsQS ( $commands , $this->qs ) ;
	}

	# This returns an array of all Wikidata protein items for the given gene $g
	function createOrAmendProteinItems ( $g , $gene_q ) {
		$ret = [] ;
		if ( !isset($g['mRNA']) ) {
#			print ( "No attributes for mRNA\n".json_encode($g)."\n" ) ;
		} else {
			foreach ( $g['mRNA'] AS $protein ) {
				if ( !isset($protein['attributes']) ) die ( "No attributes for protein\n".json_encode($g)."\n" ) ;
				$r = $this->createOrAmendProteinItem ( $gene_q , $protein ) ;
				if ( !isset($r) or $r == '' or $r === false or $r == 'Q' ) continue ; # Paranoia
				$ret[] = $r ;
			}
		}
		return $ret ;
	}

	# This returns the Wikidata item ID for a single protein
	function createOrAmendProteinItem ( $gene_q , $protein ) {
		$genedb_id = $protein['attributes']['ID'] ;
		$label = $genedb_id ;
		$desc = '' ;
		$literature = [] ;

		if ( isset($protein['attributes']['literature']) ) {
			foreach ( $protein['attributes']['literature'] AS $lit_id ) $literature[$lit_id] = 1 ;
		}

		$protein_i = new BlankWikidataItem ;

		# Claims
		$refs = [
			$protein_i->newSnak ( 'P248' , $protein_i->newItem('Q5531047') ) ,
			$protein_i->newSnak ( 'P813' , $protein_i->today() )
		] ;

		$protein_i->addClaim ( $protein_i->newClaim('P31',$protein_i->newItem('Q8054') , [$refs] ) ) ; # Instance of:protein
		$protein_i->addClaim ( $protein_i->newClaim('P279',$protein_i->newItem('Q8054') , [$refs] ) ) ; # Subclass of:protein
		$protein_i->addClaim ( $protein_i->newClaim('P703',$protein_i->newItem($this->getSpeciesQ()) , [$refs] ) ) ; # Found in:Species
		if ( $gene_q != 'LAST' ) $protein_i->addClaim ( $protein_i->newClaim('P702',$protein_i->newItem($gene_q) , [$refs] ) ) ; # Encoded by:gene
		$protein_i->addClaim ( $protein_i->newClaim('P3382',$protein_i->newString($genedb_id) , [$refs] ) ) ; # GeneDB ID

		$xref2prop = [
#			'MPMP' => '???' ,
			'UniProtKB' => 'P352'
		] ;

		if ( isset($protein['attributes']) and isset($protein['attributes']['Dbxref']) ) {
			foreach ( $protein['attributes']['Dbxref'] AS $xref ) {
				$xref = explode ( ':' , $xref , 2 ) ;
				$key = trim($xref[0]) ;
				$value = trim($xref[1]) ;
				if ( !isset($xref2prop[$key]) ) continue ;
				$prop = $xref2prop[$key] ;
				$protein_i->addClaim ( $protein_i->newClaim($prop,$protein_i->newString($value) , [$refs] ) ) ;
			}
		}

		if ( isset($protein['attributes']) and isset($protein['attributes']['product']) and is_array($protein['attributes']['product']) ) {
			foreach ( $protein['attributes']['product'] AS $v ) {
				if ( preg_match ( '/^term=(.+)$/' , $v , $m ) ) {
					if ( $label == $genedb_id ) {
						$label = $m[1] ;
						$protein_i->addAlias ( 'en' , $genedb_id ) ;
					} else {
						$protein_i->addAlias ( 'en' , $m[1] ) ;
					}
				} else if ( preg_match ( '/^with=InterPro:(.+)$/' , $v , $m ) ) {
#					$protein_i->addClaim ( $protein_i->newClaim('P2926',$protein_i->newString($m[1]) , [$refs] ) ) ; # Deactivated; applies to family?
				}
			}
		}

		if ( isset($this->go_annotation[$genedb_id]) ) {
			$new_go_claims = [] ;
			$goann = $this->go_annotation[$genedb_id] ;
			foreach ( $goann AS $ga ) {
				$go_q = $this->getItemForGoTerm ( $ga['go'] ) ;
				if ( !isset($go_q) ) {
					print "No Wikidata item for '{$ga['go']}'!\n" ;
					continue ;
				}
				if ( !isset($this->aspects[$ga['aspect']]) ) continue ;
				$aspect_p = $this->aspects[$ga['aspect']] ;
				if ( !isset($this->evidence_codes[$ga['evidence_code']]) ) continue ;
				$evidence_code_q = $this->evidence_codes[$ga['evidence_code']] ;

				$lit_sources = [] ;
				$lit_ids = explode ( '|' , $ga['db_ref'] ) ;
				foreach ( $lit_ids AS $lit_id ) {
					if ( $lit_id == 'WORKSHOP' ) continue ; // Huh
#					if ( preg_match('/^(.+?)\|/',$lit_id,$m) ) $lit_id = $m[1] ; # "1234|something" => "1234"
					$lit_q = $this->getOrCreatePaperFromID ( $lit_id ) ;
					if ( isset($lit_q) ) {
						$lit_sources[] = [ 'P248' , $protein_i->newItem($lit_q) ] ;
					} else {
						if ( preg_match ( '/^GO_REF:(\d+)$/' , $lit_id , $m ) ) {
							$lit_sources[] = [ 'P854' , $protein_i->newString('https://github.com/geneontology/go-site/blob/master/metadata/gorefs/goref-'.$m[1].'.md') ] ;
						} else if ( preg_match ( '/^InterPro:(.+)$/' , $ga['with_from'] , $m ) ) {
							$lit_sources[] = [ 'P2926' , $protein_i->newString($m[1]) ] ;
						} else {
						}
					}
				}

				$qualifiers = [
					$protein_i->newSnak ( 'P459' , $protein_i->newItem($evidence_code_q) )
				] ;

				if ( isset($ga['qualifier']) and $ga['qualifier']=='NOT' ) {
					$qualifiers[] = $protein_i->newSnak ( 'P6477' , $protein_i->newItem('Q186290') ) ; // "does not have quality:correlation"
				}

				// The with/from annotation can either be a UniProt link, a GeneDB link, InterPro ID, Pfam ID or a link to TMHMM.
				if ( isset($ga['with_from']) ) {
					if ( preg_match ( '/^Pfam:(.+)$/' , $ga['with_from'] , $m ) ) {
						$qualifiers[] = $protein_i->newSnak ( 'P3519' , $protein_i->newString($m[1]) ) ;
					} else if ( preg_match ( '/^GeneDB:(.+)$/' , $ga['with_from'] , $m ) ) {
						$qualifiers[] = $protein_i->newSnak ( 'P3382' , $protein_i->newString($m[1]) ) ;
					} else if ( preg_match ( '/^UniProt:(.+)$/' , $ga['with_from'] , $m ) ) {
						$qualifiers[] = $protein_i->newSnak ( 'P352' , $protein_i->newString($m[1]) ) ;
					} else if ( preg_match ( '/^InterPro:(.+)$/' , $ga['with_from'] , $m ) ) {
						$qualifiers[] = $protein_i->newSnak ( 'P2926' , $protein_i->newString($m[1]) ) ;
					} else if ( $ga['with_from'] == 'CBS:TMHMM' ) {
						$qualifiers[] = $protein_i->newSnak ( 'P2283' , $protein_i->newItem($this->tmhmm_q) ) ;
					}
				}

				$refs2 = [] ;
				foreach ( $lit_sources AS $lit_source ) {
					$refs2[] = [
						$protein_i->newSnak ( $lit_source[0] , $lit_source[1] ) ,
						$protein_i->newSnak ( 'P1640' , $protein_i->newItem('Q5531047') ) ,
						$protein_i->newSnak ( 'P813' , $protein_i->today() )
					] ;
				}

				$new_claim_key = json_encode([$aspect_p,$go_q,$qualifiers]) ;
				if ( isset($new_go_claims[$new_claim_key]) ) { // Similar (identical?) claim exists, just add references
					foreach ( $refs2 AS $ref ) {
						$new_go_claims[$new_claim_key][2][] = $ref ;
					}
				} else {
					$new_go_claims[$new_claim_key] = [$aspect_p,$protein_i->newItem($go_q),$refs2,$qualifiers] ;
#					$protein_i->addClaim ( $new_claim ) ;
				}

				foreach ( $lit_ids AS $lit_id ) $literature[$lit_id] = 1 ;

				if ( isset($ga['name']) and $label == $genedb_id ) {
					$label = $ga['name'] ;
					$protein_i->addAlias ( 'en' , $genedb_id ) ;
				}
				foreach ( $ga['synonym'] AS $alias ) {
					foreach ( explode(',',$this->fixAliasName($alias)) AS $v2 ) $protein_i->addAlias ( 'en' , $v2 ) ;
				}
			}
			foreach ( $new_go_claims AS $claim_parts ) {
				$new_claim = $protein_i->newClaim($claim_parts[0],$claim_parts[1],$claim_parts[2],$claim_parts[3]) ;
				$protein_i->addClaim ( $new_claim ) ;
			}
		}

		$protein_i->addLabel ( 'en' , $label ) ;
		$protein_i->addDescription ( 'en' , $desc ) ;

		if ( isset($this->protein_genedb2q[$genedb_id]) ) {
			$protein_q = $this->protein_genedb2q[$genedb_id] ;
			$this->wil->loadItems ( [$protein_q] ) ;
			$item_to_diff = $this->wil->getItem ( $protein_q ) ;
		} else {
			$item_to_diff = new BlankWikidataItem ;
			$protein_q = 'LAST' ;
		}

		$options = [
			'validator' => function ( $type , $action ,  &$old_item  , &$new_item ) { return $this->proteinEditValidator ( $type , $action , $old_item , $new_item ) ; } ,
			'ref_skip_p'=>['P813'] ,
			'labels' => [ 'ignore_except'=>['en'] ] ,
			'descriptions' => [ 'ignore_except'=>['en'] ] ,
			'aliases' => [ 'ignore_except'=>['en'] ] ,
			'remove_only' => [
				'P703', // Found in taxon
				'P680',
				'P681',
				'P682'
			]
		] ;
		$diff = $protein_i->diffToItem ( $item_to_diff , $options ) ;

		$params = (object) [
			'action' => 'wbeditentity' ,
			'data' => json_encode($diff) ,
			'summary' => 'Syncing to GeneDB (V2) ' . $this->qs->getTemporaryBatchSummary() ,
			'bot' => 1
		] ;
		if ( $protein_q == 'LAST' ) $params->new = 'item' ;
		else $params->id = $protein_q ;

		if ( $params->data == '{}' ) { # No changes
			if ( $protein_q == 'LAST' ) die ( "Cannot create empty protein for gene {$gene_q}\n" ) ; # Paranoia
		} else {
			if ( !$this->qs->runBotAction ( $params ) ) {
				print "Failed trying to edit protein '{$label}': '{$oa->error}' / ".json_encode($qs->last_res)."\n" ;
				return ;
			}
			if ( $protein_q == 'LAST' ) {
				$protein_q = $qs->last_res->entity->id ;
				$this->protein_genedb2q[$genedb_id] = $protein_q ;
			}
			$this->wil->updateItem ( $protein_q ) ; # Has changed
		}

		# attributes:literature "main subject"
		$commands = [] ;
		foreach ( $literature AS $lit_id => $dummy ) {
			$lit_q = $this->getOrCreatePaperFromID ( $lit_id ) ;
			if ( !isset($lit_q) ) continue ; # Something's wrong
			$this->wil->loadItems ( [$lit_q] ) ;
			$lit_i = $this->wil->getItem ( $lit_q ) ;
			if ( !isset($lit_i) ) continue ;
			$claims = $lit_i->getClaims('P921') ;
			if ( count($claims) >= 100 ) continue ; // Too many, Rick, too many!
			if ( $lit_i->hasTarget('P921',$protein_q) ) continue ;
			if ( !isset($protein_q) or $protein_q=='' or $protein_q=='Q' ) continue ;
			$commands[] = "{$lit_q}\tP921\t{$protein_q}" ;
		}
		$this->tfc->runCommandsQS ( $commands , $this->qs ) ;

		return $protein_q ;
	}

	function proteinEditValidator ( $type , $action ,  &$old_item  , &$new_item ) {
		 # Block removal of claims [GO terms] that have a reference a non-GeneDB curator
		if ( $type != 'claims' ) return true ;
		if ( !isset($action->id) or !isset($action->remove) ) return true ;
		$claim = $old_item->getClaimByID ( $action->id ) ;
		if ( !isset($claim) ) return true ;
		if ( !isset($claim->mainsnak) ) return true ;
		if ( !isset($claim->mainsnak->property) ) return true ;
		if ( !in_array($claim->mainsnak->property,['P680','P681','P682']) ) return true ;
		if ( !isset($claim->references) ) return true ;
		if ( count($claim->references) != 1 ) return false ; # More than one reference, do not remove
		$r = $claim->references[0] ;
		if ( !isset($r->snaks) ) return true ;
		if ( !isset($r->snaks->P1640) ) return true ;
		if ( count($r->snaks->P1640) != 1 ) return true ;
		$curator = $r->snaks->P1640[0] ;
		if ( !isset($curator->datavalue) ) return true ;
		if ( !isset($curator->datavalue->value) ) return true ;
		if ( !isset($curator->datavalue->value->id) ) return true ;
		if ( $curator->datavalue->value->id == 'Q5531047' ) return true ; # Curator: GeneDB, can be removed
		return false ;
	}


	function getItemForGoTerm ( $go_term , $cache = [] ) {
		if ( isset($this->go_term_cache[$go_term]) ) return $this->go_term_cache[$go_term] ;
		$sparql = "SELECT ?q { ?q wdt:P686 '{$go_term}' }" ;
#		$sparql = "SELECT DISTINCT ?q { ?q p:P686 ?wds . ?wds ?v '{$go_term}' }" ; # This works, but shouldn't be used, as is returns deprecated items
		$items = $this->tfc->getSPARQLitems ( $sparql ) ;
		if ( count($items) != 1 ) {
			return $this->tryUpdatedGoTerm ( $go_term , $cache ) ; # Fallback
		}
		$ret = $items[0] ;
		$this->go_term_cache[$go_term] = $ret ;
		return $ret ;
	}

	# In case a GO term was not found on Wikidata, try EBI if it was deprecates, they'll give the current ID instead
	function tryUpdatedGoTerm ( $go_term , $cache = [] ) {
		if ( isset($cache[$go_term]) ) return ; # Circular GO references?
		$url = "https://www.ebi.ac.uk/QuickGO/services/ontology/go/terms/{$go_term}/complete" ;
		$j = json_decode ( file_get_contents ( $url ) ) ;
		if ( !isset($j) or $j === false or $j == null or !isset($j->results) ) {
			print "No Wikidata item for GO term '{$go_term}'\n" ;
			return ;
		}
		if ( count($j->results) != 1 ) {
			print "Multiple GO terms for {$go_term} at {$url}\n";
			return ;
		}
		$cache[$go_term] = $go_term ;
		$go_term = $j->results[0]->id ;
		return $this->getItemForGoTerm ( $go_term , $cache ) ;
	}


/*
	// This works, but shouldn't be used, as is returns deprecated items
	function getItemForGoTermViaSearch ( $go_term ) {
		$url = "https://www.wikidata.org/w/api.php?action=query&list=search&srnamespace=0&format=json&srsearch=" . urlencode('haswbstatement:P686='.$go_term) ;
		$j = json_decode ( file_get_contents ( $url ) ) ;
		if ( !isset($j) or !isset($j->query)  or !isset($j->query->search) ) return ;
		$items = [] ;
		foreach ( $j->query->search AS $sr ) $items[$sr->title] = $sr->title ;
		$this->wil->loadItems ( $items ) ;
		$ret = [] ;
		foreach ( $items AS $q ) {
			$i = $this->wil->getItem ( $q ) ;
			if ( !isset($i) ) continue ;
			$values = $i->getStrings('P686') ;
			if ( !in_array($go_term,$values) ) continue ;
			$ret[] = $q ;
		}
		if ( count($ret) != 1 ) return ;
		$this->go_term_cache[$go_term] = $ret[0] ;
		return $ret[0] ;
	}
*/

	private function getAndCacheItemForSPARQL ( $sparql ) {
		$items = [] ;
		if ( isset($this->sparql_result_cache[$sparql]) ) {
			$items = $this->sparql_result_cache[$sparql] ;
		} else {
			$items = $this->tfc->getSPARQLitems ( $sparql ) ;
			$this->sparql_result_cache[$sparql] = $items ;
		}
		if ( count($items) == 1 ) {
			$q = $items[0] ;
			return $q ;
		}
//		if ( count($items) == 0 ) $this->log ( [ 'no_item_for_'.$what , $go ] ) ;
//		else $this->log ( [ 'multiple_item_for_'.$what , $go ] ) ;
	}

	function getOrCreatePaperFromID ( $lit_id ) {
		if ( !preg_match ( '/^(.+?):(.+)$/' , $lit_id , $m ) ) return ;
		if ( $m[1] == 'PMID' ) $prop = 'P698' ;
		else return ;
		$lit_id = $m[2] ;
		$ref_q = $this->getAndCacheItemForSPARQL ( "SELECT ?q { ?q wdt:{$prop} '{$lit_id}' }" , $prop ) ;
		if ( isset($ref_q) ) return $ref_q ;
#		print "TRYING TO CREATE NEW PUBLICATION FOR {$prop}:'{$lit_id}' => " ;
		if ( $prop == 'P698' ) $ref_q = $this->paper_editor->getOrCreateWorkFromIDs ( ['pmid'=>$lit_id] ) ;
#		print "https://www.wikidata.org/wiki/{$ref_q}\n" ;
		return $ref_q ;
	}

	function addSourceToCommands ( &$commands ) {
		$source_date = "\tS813\t+" . date('Y-m-d') . "T00:00:00Z/11" ;
		foreach ( $commands AS $cmd_num => $cmd ) {
			if ( !preg_match ( '/^(LAST|Q\d+)\tP\d+\t/' , $cmd ) ) continue ;
			if ( !preg_match ( '/\tS248\t/' , $cmd) ) $commands[$cmd_num] .= "\tS248\tQ5531047" ;
			$commands[$cmd_num] .= $source_date ;
		}
	}

	function run ( $genedb_id = '' ) {
		if ( !isset($genedb_id) ) $genedb_id = '' ;
		if ( $genedb_id != '' ) { # Single gene mode, usually for testing
			if ( !isset($this->genes[$genedb_id]) ) die ( "No such gene '{$genedb_id}'\n" ) ;
			$this->createOrAmendGeneItem ( $this->genes[$genedb_id] ) ;
			return ;
		}
		foreach ( $this->genes AS $gene ) {
			$this->createOrAmendGeneItem ( $gene ) ;
		}
	}
	
} ;


function getQS () {
	global $qs ;
	return $qs ;
}

?>