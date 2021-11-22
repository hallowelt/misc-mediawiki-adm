<?php

namespace MWStake\MediaWiki\CliAdm;

use Symfony\Component\Console\Output\OutputInterface;

class BackupDirManager {

	/**
	 *
	 * @var string
	 */
	private $backupDirPath = '';

	/**
	 *
	 * @var OutputInterface
	 */
	private $output = null;

	/**
	 *
	 * @param string $backupDirPath
	 * @param OutputInterface $output
	 */
	public function __construct( $backupDirPath, $output ) {
		$this->backupDirPath = $backupDirPath;
		$this->output = $output;
	}

	/**
	 *
	 * @param int $maxNumber
	 * @param string $backupFilePrefix
	 * @return int Number of actually removed files
	 */
	public function removeOldFiles( string $backupFilePrefix, int $maxNumber ) : int {
		if ( $maxNumber === -1 ) {
			return 0;
		}

		$numberOfRemovedFiles = 0;
		$files = glob( "{$this->backupDirPath}/$backupFilePrefix-*.zip" );
		sort( $files );
		$files = array_reverse( $files );
		$includePattern = '#' . preg_quote( "$backupFilePrefix-" ) . '\d{14}\.zip$#';
		$keptFiles = 0;
		foreach( $files as $file ) {
			$match = preg_match( $includePattern, $file );
			if ( $match === 0 ) {
				continue;
			}
			if ( $keptFiles < $maxNumber ) {
				$keptFiles++;
				continue;
			}
			$this->output->writeln( "Deleting old backup file '$file'." );
			unlink( $file );
			$numberOfRemovedFiles++;
		}
		return $numberOfRemovedFiles;
	}
}
