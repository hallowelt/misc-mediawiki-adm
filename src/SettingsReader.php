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
		$settingsFiles = new SettingsFileIterator( $this->mediawikiRoot );
		foreach( $settingsFiles as $settingsFile ) {
			$settingsFilePath = $settingsFile->getPathname();

			$content = file_get_contents( $settingsFilePath );

			preg_replace_callback(
				'#[\r\n|\n]\$wgSitename(.*?);[\r\n|\n]#im',
				function( $matches ) {
					$this->wikiName = $this->cleanMatch( $matches[1] );
					return $matches[0];
				},
				$content
			);

			preg_replace_callback(
				'#[\r\n|\n]\$wgDBname(.*?);[\r\n|\n]#im',
				function( $matches ) {
					$this->dbname = $this->cleanMatch( $matches[1] );
					return $matches[0];
				},
				$content
			);

			preg_replace_callback(
				'#[\r\n|\n]\$wgDBuser(.*?);[\r\n|\n]#im',
				function( $matches ) {
					$this->dbuser = $this->cleanMatch( $matches[1] );
					return $matches[0];
				},
				$content
			);

			preg_replace_callback(
				'#[\r\n|\n]\$wgDBpassword(.*?);[\r\n|\n]#im',
				function( $matches ) {
					$this->dbpassword = $this->cleanMatch( $matches[1] );
					return $matches[0];
				},
				$content
			);

			preg_replace_callback(
				'#[\r\n|\n]\$wgDBserver(.*?);[\r\n|\n]#im',
				function( $matches ) {
					$this->dbserver = $this->cleanMatch( $matches[1] );
					return $matches[0];
				},
				$content
			);
			preg_replace_callback(
				'#[\r\n|\n]\$wgDBprefix(.*?);[\r\n|\n]#im',
				function( $matches ) {
					$this->dbprefix = $this->cleanMatch( $matches[1] );
					return $matches[0];
				},
				$content
			);
		}
	}

	protected function cleanMatch( $match ) {
		$cleanVal = trim( $match, "=\t \"'" );
		return $cleanVal;
	}
}