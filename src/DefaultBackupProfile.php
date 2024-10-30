<?php

namespace MWStake\MediaWiki\CliAdm;

class DefaultBackupProfile implements IBackupProfile {

	/**
	 * @var array
	 */
	protected $data = [
		'target-filename' => '',
		'fs-options' => [
			'include-custom-paths' => [
				'extensions/SemanticMediaWiki/.smw.json'
			],
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
	public function getBlueSpiceFarmOptions(): ?array {
		return $this->getOption( 'bluespice-farm-options' );
	}

	/**
	 * @inheritDoc
	 */
	public function getOptions() {
		return $this->data;
	}

	/**
	 * @inheritDoc
	 */
	public function getOption( string $name, $default = null ) {
		return !empty( $this->data[$name] ) ? $this->data[$name] : $default;
	}
}