<?php

namespace MWStake\MediaWiki\CliAdm;

class JSONRestoreProfile implements IRestoreProfile {

	protected $data = [
		'fs-options' => [
			'skip-paths' => [],
			'overwrite-newer' => false,
		],
		'db-options' => [
			'connection' => [],
			'skip-tables' => [],
			'skip-tables-data' => []
		]
	];

	/**
	 *
	 * @param string $jsonFilePathname
	 */
	public function __construct( $jsonFilePathname ) {
		$contents = file_get_contents( $jsonFilePathname );
		$filedata = json_decode( $contents, true );
		if ( !$filedata ) {
			throw new \Exception( 'Invalid JSON file' );
		}
		$this->data = array_merge( $this->data, $filedata );
	}

	/**
	 *
	 * @inheritDoc
	 */
	public function getFSImportOptions() {
		return $this->data['fs-options'];
	}

	/**
	 *
	 * @inheritDoc
	 */
	public function getDBImportOptions() {
		return $this->data['db-options'];
	}

	/**
	 *
	 * @inheritDoc
	 */
	public function getOptions() {
		return $this->data;
	}

}