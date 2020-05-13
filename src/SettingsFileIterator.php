<?php

namespace MwAdmin\Cmd;

use AppendIterator;
use FilesystemIterator;
use CallbackFilterIterator;
use Exception;

class SettingsFileIterator extends AppendIterator {

	public function __construct( $dir ) {
		parent::__construct();

		$this->appendBlueSpiceFoundationConfig( $dir );
		$this->appendSettingsD( $dir );
		$this->appendRoot( $dir );
	}

	/**
	 * Existence of "$dir/extensions/BlueSpiceFoundation/config" is optional
	 * @param string $dir
	 */
	private function appendBlueSpiceFoundationConfig( $dir ) {
		try {
			$blueSpiceFoundationConfig = new FilesystemIterator(
				"$dir/extensions/BlueSpiceFoundation/config",
				FilesystemIterator::SKIP_DOTS
			);
			$this->append( $blueSpiceFoundationConfig );
		} catch( Exception $ex) {

		}
	}

	/**
	 * Existence of "$dir/settings.d" is optional
	 * @param string $dir
	 */
	private function appendSettingsD( $dir ) {
		try {
			$settingsD = new FilesystemIterator(
				"$dir/settings.d",
				FilesystemIterator::SKIP_DOTS
			);
			$this->append( $settingsD );
		} catch( Exception $ex) {

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
			$found = preg_match( '#LocalSettings.*?\.php#', $current->getFilename() );
			return $found === 1;
		} );

		$this->append( $filteredRoot );
	}
}