<?php

namespace MWStake\MediaWiki\CliAdm;

use PDO;

class BlueSpiceFarmInstanceSettingsManager {

	/**
	 * @var PDO
	 */
	protected $mainPdo;

	/**
	 * @var string
	 */
	protected $settingsTable;

	public function __construct( PDO $mainPdo, string $settingsTable ) {
		$this->mainPdo = $mainPdo;
		$this->settingsTable = $settingsTable;
	}

	/**
	 * @param string $instanceName
	 * @return array|null
	 */
	public function getFullSettings( string $instanceName ): ?array {
		// Retrieve all items where conditions $this->settingsTable.sfi_path=$this->instanceName
		$query = 'SELECT * FROM ' . $this->settingsTable . ' WHERE sfi_path = \'' . $instanceName . '\' LIMIT 1';
		$res = $this->mainPdo->query( $query );
		$res->setFetchMode( PDO::FETCH_ASSOC );
		return $res->fetch() ?? null;
	}

	public function getSettings( string $instanceName ): ?array {
		return $this->rowToSettings( $this->getFullSettings( $instanceName ) );
	}

	/**
	 * @return array
	 */
	public function getAllActiveInstances(): array {
		$query = 'SELECT sfi_path FROM ' . $this->settingsTable . ' WHERE sfi_status=\'ready\'';
		$res = $this->mainPdo->query( $query );
		$res->setFetchMode( PDO::FETCH_ASSOC );
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
		$res->setFetchMode( PDO::FETCH_ASSOC );
		$prefixes = [];
		foreach ( $res as $row ) {
			$prefixes[] = $row['sfi_db_prefix'];
		}
		return $prefixes;
	}

	/**
	 * @param string $file
	 * @return array|null
	 */
	public function getSettingsFromFile( string $file ): ?array {
		return $this->rowToSettings( $this->readInstanceSettingsFile( $file ) );
	}

	/**
	 * @param array|null $row
	 * @return array|null
	 */
	private function rowToSettings( ?array $row ) {
		if ( $row ) {
			return [
				'path' => $row['sfi_path'],
				'displayName' => $row['sfi_display_name'],
				'dbname' => $row['sfi_database'],
				'dbprefix' => $row['sfi_db_prefix'],
			];
		}
		return null;
	}

	/**
	 * @param string $file
	 * @return array|null
	 */
	private function readInstanceSettingsFile( string $file ): ?array {
		$fopen = fopen( $file, 'r' );
		if ( !$fopen ) {
			return null;
		}
		$settings = json_decode( fread( $fopen, filesize( $file ) ), true );
		fclose( $fopen );
		if ( !$settings ) {
			return null;
		}
		return $settings;
	}

	/**
	 * @param string $instanceName
	 * @param string $file
	 * @return bool
	 */
	public function assertInstanceEntryInitializedFromFile( string $instanceName, string $file ): bool {
		$checkQuery = 'SELECT COUNT(*) as cnt FROM ' . $this->settingsTable . ' WHERE sfi_path = \'' . $instanceName . '\' LIMIT 1';
		
		$res = $this->mainPdo->query( $checkQuery );
		$res->setFetchMode( PDO::FETCH_ASSOC );
		$row = $res->fetch();
		if ( $row['cnt'] > 0 ) {
			return true;
		}
		return $this->initInstanceEntryFromFile( $file );
	}

	/**
	 * @param string $file
	 * @return bool
	 */
	private function initInstanceEntryFromFile( string $file ): bool {
		$raw = $this->readInstanceSettingsFile( $file );
		if ( !$raw ) {
			return false;
		}
		$raw['sfi_status'] = 'initializing';
		$fields = array_keys( $raw );
		$query = 'INSERT INTO ' . $this->settingsTable . ' (' . implode( ',', $fields ) . ') VALUES (:' . implode( ',:', $fields ) . ')';
		$stmt = $this->mainPdo->prepare( $query );
		if ( !$stmt ) {
			return false;
		}
		foreach ( $raw as $field => $value ) {
			$stmt->bindValue( ':' . $field, $value );
		}
		return $stmt->execute( $raw );
	}

	/**
	 * @param string $instanceName
	 * @param string $field
	 * @param string $value
	 * @return void
	 */
	public function setInstanceSetting( string $instanceName, string $field, string $value ) {
		$query = 'UPDATE ' . $this->settingsTable . ' SET ' . $field . ' = \'' . $value . '\' WHERE sfi_path = \'' . $instanceName . '\'';
		$this->mainPdo->query( $query );
	}
}