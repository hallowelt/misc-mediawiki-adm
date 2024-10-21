<?php

namespace MWStake\MediaWiki\CliAdm;

class NullRestoreProfile implements IRestoreProfile {

	/**
	 *
	 * @inheritDoc
	 */
	public function getDBImportOptions() {
		return [
			"connection" => [],
			'skip-tables' => [],
			'skip-tables-data' => []
		];
	}

	/**
	 *
	 * @inheritDoc
	 */
	public function getFSImportOptions() {
		return [];
	}

	/**
	 *
	 * @inheritDoc
	 */
	public function getOptions() {
		return [];
	}
}