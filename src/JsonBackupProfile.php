<?php

namespace MWStake\MediaWiki\CliAdm;

class JsonBackupProfile implements IBackupProfile {

	/**
	 * @var array
	 */
	protected $data = [
		'fs-options' => [
			'skip-image-paths' => [
				'thumb',
				'temp',
				'cache'
			]
		],
		'db-options' => [
			'skip-tables' => [
				'objectcache',
				'l10n_cache',
				'bs_whoisonline',
				'smw_.*?'
			]
		]
	];

	/**
	 * @param string $jsonFilePathname
	 */
	public function __construct( string $jsonFilePathname ) {
		$contents = file_get_contents( $jsonFilePathname );
		$filedata = json_decode( $contents, true );

		$this->data = array_merge( $this->data, $filedata );
	}

	/**
	 * @inheritDoc
	 */
	public function getFSBackupOptions() {
		return $this->data['fs-options'];
	}

	/**
	 * @inheritDoc
	 */
	public function getDBBackupOptions() {
		return $this->data['db-options'];
	}

	/**
	 * @inheritDoc
	 */
	public function getOptions() {
		return $this->data;
	}
}