<?php

namespace MWStake\MediaWiki\CliAdm;

class DefaultBackupProfile implements IBackupProfile {

	/**
	 * @var array
	 */
	protected $data = [
		'fs-options' => [
			'skip-image-paths' => [
				'thumb',
				'temp',
				'cache'
			],
			'additional-files' => []
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
	public function getOptions() {
		return $this->data;
	}
}