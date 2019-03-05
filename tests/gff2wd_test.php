<?PHP

# Requires PHP7
# Run with ./vendor/bin/phpunit --bootstrap vendor/autoload.php itemdiffTest.php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;

require_once ( __DIR__ . '/../gff2wd.php' ) ;


/**
 * @covers gff2wd
 */
final class gff2wdTest extends TestCase {

	private $config_path = 'https://www.genedb.org/data/datasets.json' ;
	private $config ;
	private $gff2wd ;

	private function setOrganismConfig ( $sk , &$gff2wd ) {
		if ( !isset($this->config) ) $this->config = json_decode (file_get_contents ( $this->config_path ) ) ;
		foreach ( $this->config AS $group => $entries ) {
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
	}

	// Creates new gff2wd instance for Pfalciparum, or returns previous one
	private function createTestObject() {
		if ( isset($this->gff2wd) ) return $this->gff2wd ;
		$this->gff2wd =  new GFF2WD ;
		$this->setOrganismConfig ( 'Pfalciparum' , $this->gff2wd ) ;
		return $this->gff2wd ;
	}

	public function testCanCreate() :void {
		$gffw2d = $this->createTestObject() ;
		$this->assertEquals ( $gffw2d->gffj->wikidata_id , 'Q61779043' ) ;
	}

	public function testCanGetReference() :void {
		$gff2wd = $this->createTestObject() ;
		$gff2wd->ensureConfigComplete() ;
		$this->assertEquals ( $gff2wd->gffj->genomic_assembly , 'Q61815002' ) ;
	}

	public function testCanGenerateFilenames() :void {
		$gff2wd = $this->createTestObject() ;
		$this->assertEquals ( $gff2wd->computeFilenameGFF() , 'ftp://ftp.sanger.ac.uk/pub/genedb/releases/latest/Pfalciparum/Pfalciparum.gff.gz' ) ;
		$this->assertEquals ( $gff2wd->computeFilenameGAF() , 'ftp://ftp.sanger.ac.uk/pub/genedb/releases/latest/Pfalciparum/Pfalciparum.gaf.gz' ) ;
	}

/*
		$this->assertTrue ( isset($i) ) ;
*/	
}


?>