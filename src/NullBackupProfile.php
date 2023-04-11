<?php

namespace MWStake\MediaWiki\CliAdm;

class NullBackupProfile implements IBackupProfile {

	/**
	 * @inheritDoc
	 */
	public function getFSBackupOptions() {
		return [
			'skip-image-paths' => [
				'thumb',
				'temp',
				'cache'
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getDBBackupOptions() {
		return [
			'skip-tables' => [
				'objectcache',
				'l10n_cache',
				'bs_whoisonline',
				'smw_.*?'
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getOptions() {
		return [
			'db-options' => [
				$this->getDBBackupOptions()
			],
			'fs-options' => [
				$this->getFSBackupOptions()
			]
		];
	}
}