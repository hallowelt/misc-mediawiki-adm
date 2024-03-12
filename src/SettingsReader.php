<?php

namespace MWStake\MediaWiki\CliAdm;

use RecursiveIteratorIterator;
use MWStake\MediaWiki\CliAdm\SettingsFileIterator;

class SettingsReader {

	protected $mediawikiRoot = '';

	public function getSettingsFromDirectory( $dirpath ) {
		$this->mediawikiRoot = $dirpath;
		$this->readInSettingsFile();

		return [
			'wikiName' => $this->wikiName,
			'dbname' => $this->dbname,
			'dbuser' => $this->dbuser,
			'dbpassword' => $this->dbpassword,
			'dbserver' => $this->dbserver,
			'dbprefix' => $this->dbprefix
		];
	}

	protected $wikiName = '';
	protected $dbname = '';
	protected $dbuser = '';
	protected $dbpassword = '';
	protected $dbserver = '';
	protected $dbprefix = '';

	protected function readInSettingsFile() {
		$settingsFilesIterator = new SettingsFileIterator( $this->mediawikiRoot );
		$settingsFiles = [];
		foreach( $settingsFilesIterator as $settingsFile ) {
			$settingsFiles[] = $settingsFile;
		}
		usort( $settingsFiles, function( $a, $b ) {
			$filenameA = $a->getFilename();
			$filenameB = $b->getFilename();

			$fileDescA = $this->getFileDesc( $filenameA );
			$fileDescB = $this->getFileDesc( $filenameB );

			if ( $this->isBackupFile( $fileDescA ) ) {
				return -1;
			}

			if ( $this->isBackupFile( $fileDescB ) ) {
				return +1;
			}

			if ( $this->isLocalOverride( $fileDescA ) ) {
				return 1;
			}

			if ( $this->isNumberedFile( $fileDescA ) ) {
				return +1;
			}

			return 0;
		} );

		foreach( $settingsFiles as $settingsFile ) {
			$settingsFilePath = $settingsFile->getPathname();

			$content = file_get_contents( $settingsFilePath );
			$lines = explode( "\n", $content );
			$lines = array_map( 'trim', $lines );
			foreach( $lines as $line ) {
				$this->parseLine( $line );
			}
		}
	}

	private function parseLine( $line ) {
		if ( strpos( $line, '#' ) === 0 ) {
			return;
		}
		if ( strpos( $line, '//' ) === 0 ) {
			return;
		}
		if ( substr( $line, -1 ) !== ';' ) {
			return;
		}
		if ( strpos( $line, '=' ) === false ) {
			return;
		}
		$parts = explode( '=', $line, 2 );
		$varName = trim( $parts[0] );
		// $varName can be `$GLOBALS['wgServer']`, `$GLOBALS["wgServer"]` or just `$wgServer`
		$cleanedName = str_replace( [ '$GLOBALS[', ']', '"', "'", '$' ], '', $varName );
		$varValue = trim( $parts[1], "\t ;" );
		$cleanedValue = preg_replace( '#^["\'](.*?)["\']$#', '$1', $varValue );
		if ( $this->isFunctionCall( $cleanedValue ) ) {
			return;
		}

		$this->setInternal( $cleanedName, $cleanedValue );
	}

	private function setInternal( $varName, $varValue ) {

		switch( $varName ) {
			case 'wgSitename':
				$this->wikiName = $varValue;
				break;
			case 'wgDBname':
				$this->dbname = $varValue;
				break;
			case 'wgDBuser':
				$this->dbuser = $varValue;
				break;
			case 'wgDBpassword':
				$this->dbpassword = $varValue;
				break;
			case 'wgDBserver':
				$this->dbserver = $varValue;
				break;
			case 'wgDBprefix':
				$this->dbprefix = $varValue;
				break;
		}
	}

	private function getFileDesc( $filename ) {
		$desc = [
			'prefix' => '',
			'infix' => '',
			'name' => $filename
		];

		$prefixParts = explode( '-', $filename );
		if ( count( $prefixParts ) > 1 ) {
			$desc['prefix'] = $prefixParts[0];
		}

		$infixParts = explode( '.', $filename );
		if ( count( $infixParts ) > 2 ) {
			$desc['infix'] = $infixParts[count( $infixParts ) - 2];
		}

		return $desc;
	}

	private function isBackupFile( $fileDesc ) {
		$infix = $fileDesc['infix'];
		$infix = strtolower( $infix );
		if ( strpos( $infix, 'bak' ) === 0 ) {
			return true;
		}
		if ( strpos( $infix, 'backup' ) === 0 ) {
			return true;
		}
		if ( strpos( $infix, 'old' ) === 0 ) {
			return true;
		}
	}

	private function isNumberedFile( $fileDesc ) {
		return is_numeric( $fileDesc['prefix'] );
	}

	private function isLocalOverride( $fileDesc ) {
		return $fileDesc['infix'] === 'local';
	}

	private function isFunctionCall( $value ) {
		$regex = '#^[a-zA-Z0-9]+\(.*?\)$#';
		return preg_match( $regex, $value ) === 1;
	}
}