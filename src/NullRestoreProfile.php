<?php

namespace MwAdmin\Cmd;

use MwAdmin\Cmd\IRestoreProfile;

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