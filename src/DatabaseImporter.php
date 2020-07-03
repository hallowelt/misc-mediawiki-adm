<?php

namespace MWStake\MediaWiki\CliAdm;

use PDO;
use PDOException;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Output\Output;

class DatabaseImporter {

	const OPT_SKIP_TABLES = 'skip-tables';

	/**
	 *
	 * @var PDO
	 */
	private $pdo = null;

	/**
	 *
	 * @var Input
	 */
	private $input = null;

	/**
	 *
	 * @var Output
	 */
	private $output = null;

	/**
	 *
	 * @var array
	 */
	private $importOptions = [
		self::OPT_SKIP_TABLES => []
	];

	/**
	 *
	 * @param PDO $pdo
	 * @param Input $input
	 * @param Output $output
	 */
	public function __construct( $pdo, $input, $output, $importOptions ) {
		$this->pdo = $pdo;
		$this->input = $input;
		$this->output = $output;
		foreach( $this->importOptions as $key => $option ) {
			if ( !isset( $importOptions[$key] ) ) {
				continue;
			}
			$this->importOptions[$key] = $importOptions[$key];
		}
	}

	/**
	 *
	 * @param string $pathname
	 * @param array $importOptions
	 */
	public function importFile( $pathname ) {
		$this->readInAndCheckTableNames( $pathname );
		if( $this->readyToImport() ) {
			$this->doImport( $pathname );
		}
	}

	private function readInAndCheckTableNames( $pathname ) {
		$content= file_get_contents( $pathname );
		$tables = [];
		preg_replace_callback(
			'#CREATE TABLE `(.*?)` \(#si',
			function( $matches ) use ( &$tables ) {
				$tables[] = $matches[1];
				return $matches[0];
			},
			$content
		);
	}

	private function readyToImport() {
		return true;
	}

	/**
	 * https://bedigit.com/blog/import-mysql-large-database-sql-file-using-pdo/
	 */
	private function doImport( $pathname ) {
		$errorDetect = false;
		$tmpLine = '';
		$lines = file( $pathname );
		foreach ($lines as $line) {
			if ( $this->isCommentLine( $line ) ) {
				continue;
			}

			$this->setCurrentLinesTable( $line );
			if( $this->skipCurrentLine() ) {
				$this->output->write( "s" );
				continue;
			}

			$tmpLine .= $line;

			if ( $this->isEndOfQuery( $line ) ) {
				try {
					//$this->output->writeln( $tmpLine );
					$rowNum = $this->pdo->exec( $tmpLine );
					//$this->pdo->commit();
					$this->output->write( '.' );
				} catch (PDOException $e) {
					$this->output->writeln(
						"<error>Error performing Query: " . $tmpLine . ": "
							. $e->getMessage() . "</error>"
					);
					$errorDetect = true;
				}
				$tmpLine = '';
			}
		}

		if ($errorDetect) {
			return false;
		}

	}

	private function isCommentLine( $line ) {
		return substr( $line, 0, 2 ) == '--' || trim( $line ) == '';
	}

	private function isEndOfQuery( $line ) {
		return substr( trim( $line ), -1, 1 ) == ';';
	}

	/**
	 *
	 * @var string
	 */
	private $currentLinesTable = '';

	/**
	 *
	 * @var string[]
	 */
	private $currentLinesTablePatterns = [
		'#^DROP TABLE IF EXISTS `(.*?)`;#',
		'#^CREATE TABLE `(.*?)` \(#',
		'#^LOCK TABLES `(.*?)` WRITE;#',
		'#^INSERT INTO `(.*?)` VALUES#'
	];

	private function setCurrentLinesTable( $line ) {
		$previousLinesTable = $this->currentLinesTable;
		preg_replace_callback(
			$this->currentLinesTablePatterns,
			function( $matches ) {
				$this->currentLinesTable = $matches[1];
				return $matches[0];
			},
			$line
		);
		if( $previousLinesTable !== $this->currentLinesTable ) {
			$this->output->writeln( "\nProcessing table '{$this->currentLinesTable}'" );
		}
	}

	private function skipCurrentLine() {
		if ( in_array( $this->currentLinesTable, $this->importOptions[static::OPT_SKIP_TABLES] ) ) {
			return true;
		}
		return false;
	}
}