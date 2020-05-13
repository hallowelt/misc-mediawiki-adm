<?php

namespace MwAdmin\Cmd;

interface IRestoreProfile {

	/**
	 * @return array
	 */
	public function getFSImportOptions();

	/**
	 * return array
	 */
	public function getDBImportOptions();

	/**
	 * return array
	 */
	public function getOptions();
}