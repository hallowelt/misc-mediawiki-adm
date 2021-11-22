<?php

namespace MWStake\MediaWiki\CliAdm\Tests;

use MWStake\MediaWiki\CliAdm\BackupDirManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

class BackupDirManagerTest extends TestCase {

	/**
	 *
	 * @return void
	 */
	public function setup(): void {

	}

	/**
	 *
	 * @var string
	 */
	private $backupDirPath = '';

	/**
	 *
	 * @var array
	 */
	private $testFiles = [];

	/**
	 * @covers MWStake\MediaWiki\CliAdm\BackupDirManager::removeOldFiles
	 * @return void
	 */
	public function testRemoveOldFiles() {

		$this->makeBackupDir( 'Test1' );
		$output = $this->createMock( OutputInterface::class );
		$backupDirManager = new BackupDirManager( $this->backupDirPath, $output );

		// Delete two out of five
		$numberOfDeletedFiles = $backupDirManager->removeOldFiles( 'mediawiki', 2 );
		$this->assertEquals( 2, $numberOfDeletedFiles );
		$this->assertFileNotExists( $this->testFiles[0] );
		$this->assertFileNotExists( $this->testFiles[1] );
		$this->assertFileExists( $this->testFiles[2] );
		$this->assertFileExists( $this->testFiles[3] );
		$this->assertFileExists( $this->testFiles[4] );

		// Delete nothing
		$this->makeBackupDir( 'Test2' );
		$numberOfDeletedFiles = $backupDirManager->removeOldFiles( 'mediawiki', -1 );
		$this->assertEquals( 0, $numberOfDeletedFiles );
		$this->assertFileExists( $this->testFiles[0] );
		$this->assertFileExists( $this->testFiles[1] );
		$this->assertFileExists( $this->testFiles[2] );
		$this->assertFileExists( $this->testFiles[3] );
		$this->assertFileExists( $this->testFiles[4] );
	}

	private function makeBackupDir( $name ) {
		$this->backupDirPath = sys_get_temp_dir() . "/mediawiki-cmd-backupmanager-test-$name-" . time();
		mkdir( $this->backupDirPath, 0777, true );

		$this->testFiles = [
			"{$this->backupDirPath}/mediawiki-20210101000000.zip",
			"{$this->backupDirPath}/mediawiki-20210102000000.zip",
			"{$this->backupDirPath}/mediawiki-20210103000000.zip",
			"{$this->backupDirPath}/mediawiki-20210104000000.zip",
			"{$this->backupDirPath}/mediawiki-20210101000000.bak.zip",
		];
		foreach ( $this->testFiles as $testFile ) {
			$fh = fopen( $testFile, 'w' );
			fputs( $fh, 'Nothing' );
			fclose( $fh );
			$this->assertFileExists( $testFile );
		}
	}
}
