<?php

namespace MWStake\MediaWiki\CliAdm;

class JSONRestoreProfile implements IRestoreProfile {

	protected $data = [
		'db-exclude' => [],
		'fs-exclude' => []
	];

	public function __construct( $jsonFilePathname ) {
		$contents = file_get_contents( $jsonFilePathname );
		$filedata = json_decode( $contents, true );

		$this->data = array_merge( $this->data, $filedata );
	}

	public function getFSImportOptions() {
		return $this->data['db-exclude'];
	}

	public function getDBImportOptions() {
		return $this->data['fs-exclude'];
	}

	public function getOptions() {
		return $this->data;
	}

}