<?php

namespace MWStake\MediaWiki\CliAdm;

use AppendIterator;
use FilesystemIterator;
use CallbackFilterIterator;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SettingsFileIterator extends AppendIterator {

	public function __construct( $dir ) {
		parent::__construct();

		$this->appendSettingsD( $dir );
		$this->appendRoot( $dir );
	}

	/**
	 * Existence of "$dir/settings.d" is optional
	 * @param string $dir
	 */
	private function appendSettingsD( $dir ) {
		try {
				$settingsD = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator(
						"$dir/settings.d",
						FilesystemIterator::SKIP_DOTS
					)
			);
			$this->append( $settingsD );
		} catch( Exception $ex ) {

		}
	}

	/**
	 *
	 * @param string $dir
	 */
	private function appendRoot( $dir ) {
		$root = new FilesystemIterator(
			$dir,
			FilesystemIterator::SKIP_DOTS
		);
		$filteredRoot = new CallbackFilterIterator( $root, function ($current, $key, $iterator) {
			$found = preg_match( '#LocalSettings.*?\.php$#', $current->getFilename() );
			return $found === 1;
		} );

		$this->append( $filteredRoot );
	}
}
