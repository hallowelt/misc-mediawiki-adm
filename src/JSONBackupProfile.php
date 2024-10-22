<?php

namespace MWStake\MediaWiki\CliAdm;

class JSONBackupProfile extends DefaultBackupProfile {

	/**
	 * @param string $jsonFilePathname
	 */
	public function __construct( string $jsonFilePathname ) {
		$contents = file_get_contents( $jsonFilePathname );
		$filedata = json_decode( $contents, true );
		if ( !$filedata ) {
			throw new \Exception( 'Invalid JSON file' );
		}
		$this->data = array_merge( $this->data, $filedata );
	}
}