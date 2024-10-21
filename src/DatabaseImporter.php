<?php

namespace MWStake\MediaWiki\CliAdm;

use PDO;
use PDOException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Output\Output;

class DatabaseImporter {

	const OPT_SKIP_TABLES = 'skip-tables';

	const OPT_SKIP_TABLES_DATA = 'skip-tables-data';

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
		self::OPT_SKIP_TABLES => [],
		self::OPT_SKIP_TABLES_DATA => []
	];

	/**
	 *
	 * @param PDO $pdo
	 * @param Input $input
	 * @param Output $output
	 * @param array $importOptions
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
		$this->doImport( $pathname );
	}

	/**
	 * https://bedigit.com/blog/import-mysql-large-database-sql-file-using-pdo/
	 */
	private function doImport( $pathname ) {
		$errorDetect = false;
		$tmpLine = '';
		ini_set( "memory_limit", 0 );
		$lines = file( $pathname );
		$progressBar = new ProgressBar( $this->output, count( $lines ) );
		foreach ($lines as $line) {
			if ( $this->isCommentLine( $line ) ) {
				$progressBar->advance();
				continue;
			}

			$this->setCurrentLinesTable( $line, $progressBar );
			if ( $this->skipCurrentLine() ) {
				$progressBar->advance();
				continue;
			}

			$tmpLine .= $line;

			if ( $this->isEndOfQuery( $line ) ) {
				try {
					$this->pdo->beginTransaction();
					$this->pdo->exec( $tmpLine );
					if ($this->pdo->inTransaction() ) {
						$this->pdo->commit();
					}
				} catch (PDOException $e) {
					$this->output->writeln(
						"<error>Error performing Query: " . $tmpLine . ": "
							. $e->getMessage() . "</error>"
					);
					$errorDetect = true;
				}
				$progressBar->advance();
				$tmpLine = '';
			}
		}
		$progressBar->setMessage( 'Done.' );
		$progressBar->finish();

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
	 * @var string
	 */
	private $currentLinesOperation = '';

	/**
	 *
	 * @var string[]
	 */
	private $currentLinesTablePatterns = [
		'#^(DROP) TABLE IF EXISTS `(.*?)`;#',
		'#^(CREATE) TABLE `(.*?)` \(#',
		'#^(LOCK) TABLES `(.*?)` WRITE;#',
		'#^(INSERT) INTO `(.*?)` VALUES#'
	];

	/**
	 *
	 * @param string $line
	 * @param ProgressBar $progressBar
	 * @return void
	 */
	private function setCurrentLinesTable( $line, $progressBar ) {
		$previousLinesTable = $this->currentLinesTable;
		preg_replace_callback(
			$this->currentLinesTablePatterns,
			function( $matches ) {
				$this->currentLinesOperation = $matches[1];
				$this->currentLinesTable = $matches[2];
				return $matches[0];
			},
			$line
		);
		if( $previousLinesTable !== $this->currentLinesTable ) {
			$progressBar->setMessage( $this->currentLinesTable );
		}
	}

	private function skipCurrentLine() {
		if ( in_array( $this->currentLinesTable, $this->importOptions[static::OPT_SKIP_TABLES] ) ) {
			return true;
		}

		$shouldSkipTablesData = in_array(
			$this->currentLinesTable,
			$this->importOptions[static::OPT_SKIP_TABLES_DATA]
		);
		$isInsertData = $this->currentLinesOperation === 'INSERT';
		if ( $shouldSkipTablesData && $isInsertData ) {
			return true;
		}
		return false;
	}
}