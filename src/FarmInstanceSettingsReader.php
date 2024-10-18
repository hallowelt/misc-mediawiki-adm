<?php

namespace MWStake\MediaWiki\CliAdm;

use RecursiveIteratorIterator;
use MWStake\MediaWiki\CliAdm\SettingsFileIterator;

class FarmInstanceSettingsReader {

	/**
	 * @var \PDO
	 */
	protected $mainPdo;

	/**
	 * @var string
	 */
	protected $settingsTable;

	public function __construct( \PDO $mainPdo, string $settingsTable ) {
		$this->mainPdo = $mainPdo;
		$this->settingsTable = $settingsTable;
	}

	public function getSettings( string $instanceName ): ?array {
		// Retrieve all items where conditions $this->settingsTable.sfi_path=$this->instanceName
		$query = 'SELECT * FROM ' . $this->settingsTable . ' WHERE sfi_path = \'' . $instanceName . '\' LIMIT 1';
		$res = $this->mainPdo->query( $query );
		$res->setFetchMode( \PDO::FETCH_ASSOC );
		$row = $res->fetch();
		if ( $row ) {
			return [
				'displayName' => $row['sfi_display_name'],
				'dbname' => $row['sfi_database'],
				'dbprefix' => $row['sfi_db_prefix'],
			];
		}
		return null;
	}

	/**
	 * @return array
	 */
	public function getAllActiveInstances(): array {
		$query = 'SELECT sfi_path FROM ' . $this->settingsTable . ' WHERE sfi_status=\'ready\'';
		$res = $this->mainPdo->query( $query );
		$res->setFetchMode( \PDO::FETCH_ASSOC );
		$instances = [];
		foreach ( $res as $row ) {
			$instances[] = $row['sfi_path'];
		}
		return $instances;
	}

	/**
	 * @param string $dbName
	 * @return array
	 */
	public function getAllInstancePrefixes( string $dbName ): array {
		$query = 'SELECT sfi_db_prefix FROM ' . $this->settingsTable . ' WHERE sfi_database=\'' . $dbName . '\'';
		$res = $this->mainPdo->query( $query );
		$res->setFetchMode( \PDO::FETCH_ASSOC );
		$prefixes = [];
		foreach ( $res as $row ) {
			$prefixes[] = $row['sfi_db_prefix'];
		}
		return $prefixes;
	}
}