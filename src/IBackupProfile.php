<?php

namespace MWStake\MediaWiki\CliAdm;

use Ifsnop\Mysqldump\Mysqldump;

interface IBackupProfile {

	/**
	 * Option "skip-image-paths" - which image folders will be excluded from back-up.
	 *
	 * @return array
	 */
	public function getFSBackupOptions();

	/**
	 * Option "skip-tables" - which tables will be excluded from back-up.
	 * That array of table names will be passed to "no-data" parameter of {@link Mysqldump}
	 *
	 * @return array
	 */
	public function getDBBackupOptions();

	/**
	 * @return array|null
	 */
	public function getBlueSpiceFarmOptions(): ?array;

	/**
	 * Key "db-options" should contain DB backup options.
	 * Key "fs-options" should contain filesystem backup options.
	 *
	 * @return array
	 */
	public function getOptions();

	/**
	 * @param string $name
	 * @param mixed|null $default
	 * @return mixed
	 */
	public function getOption( string $name, $default = null );
}