<?php

namespace MWStake\MediaWiki\CliAdm;

use MWStake\MediaWiki\CliAdm\IRestoreProfile;

class NullRestoreProfile implements IRestoreProfile {

	public function getDBImportOptions() {
		return [];
	}

	public function getFSImportOptions(): array {
		return [];
	}

	public function getOptions() {
		return [];
	}
}