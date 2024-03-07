<?php

namespace MWStake\MediaWiki\CliAdm\Commands;

use DateTime;
use MWStake\MediaWiki\CliAdm\IBackupProfile;
use MWStake\MediaWiki\CliAdm\JSONBackupProfile;
use MWStake\MediaWiki\CliAdm\DefaultBackupProfile;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use ZipArchive;
use Ifsnop\Mysqldump\Mysqldump;
use MWStake\MediaWiki\CliAdm\BackupDirManager;
use MWStake\MediaWiki\CliAdm\SettingsReader;
use MWStake\MediaWiki\CliAdm\SettingsFileIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class WikiBackup extends Command {

	/**
	 * @var DateTime
	 */
	private $startTime = null;

	/**
	 * @var DateTime
	 */
	private $endTime = null;

	/**
	 *
	 * @var Input\InputInterface
	 */
	protected $input = null;

	/**
	 *
	 * @var OutputInterface
	 */
	protected $output = null;

	/**
	 *
	 * @var string
	 */
	protected $mediawikiRoot = '.';

	/**
	 *
	 * @var string
	 */
	protected $dest = '';

	/**
	 *
	 * @var boolean
	 */
	protected $omitTimestamp = false;

	/**
	 *
	 * @var integer
	 */
	private $maxBackupFiles = -1;

	/**
	 * @var IBackupProfile
	 */
	protected $profile = null;

	/**
	 *
	 * @var ZipArchive
	 */
	protected $zip = null;

	protected function configure() {
		parent::configure();
		$this
			->setName( 'wiki-backup' )
			->setDescription( 'Creates a ZIP archive containing all necessary data elements' )
			->setDefinition( new Input\InputDefinition( [
				new Input\InputOption(
					'mediawiki-root',
					null,
					Input\InputOption::VALUE_OPTIONAL,
					'Specifies the diretory, which holds the mediawiki codebase',
					'.'
				),
				new Input\InputOption(
					'dest',
					null,
					Input\InputOption::VALUE_OPTIONAL,
					'Specifies the directory to store the backup file',
					'.'
				),
				new Input\InputOption(
					'omit-timestamp',
					null,
					Input\InputOption::VALUE_NONE,
					'Have no timestamp in the resulting filename',
					null
				),
				new Input\InputOption(
					'max-backup-files',
					null,
					Input\InputOption::VALUE_OPTIONAL,
					'Number of files with the same filename-prefix to keep',
					-1
				),
				new Input\InputOption(
					'profile',
					null,
					Input\InputOption::VALUE_OPTIONAL,
					'Specifies a profile for the back-up'
				),
			] ) );
	}

	protected function execute( Input\InputInterface $input, OutputInterface $output ) {
		$this->outputStartInfo( $output );
		$this->input = $input;
		$this->output = $output;

		$this->mediawikiRoot = $input->getOption( 'mediawiki-root' );
		$this->dest = realpath( $input->getOption( 'dest' ) );
		$this->omitTimestamp = $input->getOption( 'omit-timestamp' );
		$this->maxBackupFiles = (int) $input->getOption( 'max-backup-files' );

		$this->loadProfile( $input->getOption( 'profile' ) );

		$this->readInSettingsFile();
		$this->checkDatabaseConnection();
		$this->initZipFile();
		$this->addSettingsFiles();
		$this->addImagesFolder();
		$this->dumpDatabase();

		$this->zip->close();
		$this->cleanUp();

		$this->removeOldBackups();

		$this->output->writeln( '<info>--> Done.</info>' );
		$this->outputEndInfo( $output );
	}

	protected $wikiName = '';
	protected $dbname = '';
	protected $dbuser = '';
	protected $dbpassword = '';
	protected $dbserver = '';
	protected $dbprefix = '';

	protected function readInSettingsFile() {
		$profileData = $this->profile->getDBBackupOptions();
		if ( isset( $profileData['connection'] ) ) {
			$connection = $profileData['connection'];
			$this->dbname = $connection['dbname'] ?? '';
			$this->dbuser = $connection['dbuser'] ?? '';
			$this->dbpassword = $connection['dbpassword'] ?? '';
			$this->dbserver = $connection['dbserver'] ?? '';
		}

		$settingsReader = new SettingsReader();
		$settings = $settingsReader->getSettingsFromDirectory( $this->mediawikiRoot );

		$requiredFields = [
			'dbname', 'dbpassword', 'dbuser', 'dbserver', 'wikiName'
		];

		foreach( $requiredFields as $requiredField ) {
			if( !empty( $this->{$requiredField} ) ) {
				// Already set by the provided backup-profile
				continue;
			}
			if( empty( $settings[$requiredField] ) ) {
				throw new \Exception( "Required information '$requiredField' "
						. "could not be extracted!" );
			}
			$this->{$requiredField} = $settings[$requiredField];
		}
	}

	private function checkDatabaseConnection() {
		$this->output->writeln( "Checking database connection ..." );
		$this->output->writeln( "Connecting to '{$this->dbserver}/{$this->dbname}' as {$this->dbname}..." );
		try {
			new \PDO(
				"mysql:host={$this->dbserver};dbname={$this->dbname}",
				$this->dbuser,
				$this->dbpassword
			);
		} catch( \PDOException $ex ) {
			throw new \Exception( "Could not connect to database: " . $ex->getMessage() );
		}
	}

	protected function initZipFile() {
		$destFilePath = $this->makeDestFilepath();
		$this->output->writeln( "Creating file '$destFilePath' ..." );
		$this->zip = new ZipArchive();
		$this->zip->open(
			$destFilePath,
			ZipArchive::CREATE|ZipArchive::OVERWRITE
		);
	}

	protected function makeDestFilepath() {
		$suffix = '';
		if ( !$this->omitTimestamp ) {
			$timestamp = date( 'YmdHis' );
			$suffix = "-$timestamp";
		}

		return "{$this->dest}/{$this->wikiName}{$suffix}.zip";
	}

	protected function addImagesFolder() {
		$imagesDir = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					"{$this->mediawikiRoot}/images"
				)
		);
		$imagesToBackup = [];
		$this->output->writeln( "Adding 'images/' ..." );

		$skipFolders = [];

		$filesystemOptions = $this->profile->getFSBackupOptions();
		if ( isset( $filesystemOptions['skip-image-paths'] ) ) {
			$skipFolders = $filesystemOptions['skip-image-paths'];
		}

		foreach( $imagesDir as $fileInfo ) {
			if( $fileInfo->isDir() ) {
				continue;
			}
			$blacklisted = false;
			foreach( $skipFolders as $folder ) {
				$skipBasePath = "{$this->mediawikiRoot}/images/$folder";
				if( strpos( $fileInfo->getPathname(), $skipBasePath ) === 0 ) {
					$blacklisted = true;
					break;
				}
			}
			if( $blacklisted ) {
				continue;
			}
			$imagesToBackup[] = $fileInfo->getPathname();
		}

		$progressBar = new ProgressBar(
			$this->output,
			count( $imagesToBackup )
		);

		$pregPattern = '#^' . preg_quote( "{$this->mediawikiRoot}/" ) .'#';
		foreach( $imagesToBackup as $path ) {
			$localPath = preg_replace( $pregPattern, '', $path );
			$this->zip->addFile( $path, "filesystem/$localPath" );
			$progressBar->advance();
		}

		$progressBar->finish();
		$this->output->write( "\n" );
	}

	protected $tmpDumpFilepath = '';

	protected function dumpDatabase() {
		$this->output->writeln(
			"Dumping '{$this->dbserver}/{$this->dbname}' ..."
		);

		$skipTables = [];

		$dbOptions = $this->profile->getDBBackupOptions();
		if ( isset( $dbOptions['skip-tables'] ) ) {
			$skipTables = $dbOptions['skip-tables'];
		}

		$dumpSettings = [
			'add-drop-table' => true,
			'no-data' => array_map( function( $item ) use ( $skipTables ) {
				return $this->dbprefix.$item;
			}, $skipTables ),
			'skip-definer' => true
		];

		$dump = new Mysqldump(
			"mysql:host={$this->dbserver};dbname={$this->dbname}",
			$this->dbuser,
			$this->dbpassword,
			$dumpSettings
		);

		$tmpPath = sys_get_temp_dir();
		$this->tmpDumpFilepath = "$tmpPath/{$this->dbname}.sql";

		$dump->start( $this->tmpDumpFilepath );

		$localPath = "database.sql";
		$this->output->writeln( "Adding '$localPath' ..." );
		$this->zip->addFile( $this->tmpDumpFilepath, $localPath );
	}

	protected function addSettingsFiles() {
		$settingsFiles = new SettingsFileIterator( $this->mediawikiRoot );

		$progressBar = new ProgressBar(
			$this->output,
			count( $settingsFiles->getArrayIterator() )
		);
		$quotedMediaWikiRoot = preg_quote( $this->mediawikiRoot );
		$mediaWikiRootStripPattern = "#^$quotedMediaWikiRoot#";
		$this->output->writeln( "Adding 'settings' ..." );
		foreach( $settingsFiles as $settingsFile ) {
			$path = $settingsFile->getPathname();
			$localPath = preg_replace( $mediaWikiRootStripPattern, '', $path  );
			$localPath = trim( $localPath, '/' );
			$this->zip->addFile( $path, "filesystem/$localPath" );
			$progressBar->advance();
		}
		$progressBar->finish();
		$this->output->write( "\n" );
	}

	protected function cleanUp() {
		unlink( $this->tmpDumpFilepath );
	}

	/**
	 * @param $profileFilepath
	 */
	private function loadProfile( $profileFilepath ) {
		if( $profileFilepath === null ) {
			$this->profile = new DefaultBackupProfile();
			return;
		}

		$profileFile = new SplFileInfo( $profileFilepath );
		$extension = strtolower( $profileFile->getExtension() );

		if( $extension === 'json') {
			$this->profile = new JSONBackupProfile( $profileFile->getPathname() );
			return;
		}
		$this->profile = new DefaultBackupProfile();
	}

	/**
	 *
	 * @param OutputInterface $output
	 * @return void
	 */
	private function outputStartInfo( $output ) {
		$this->startTime = new DateTime();
		$formattedTimestamp = $this->startTime->format( 'Y-m-d H:i:s');
		$output->writeln( "Starting ($formattedTimestamp)" );
	}

	/**
	 *
	 * @param OutputInterface $output
	 * @return void
	 */
	private function outputEndInfo( $output ) {
		$this->endTime = new DateTime();
		$formattedTimestamp = $this->endTime->format( 'Y-m-d H:i:s');
		$scriptRunTime = $this->endTime->diff( $this->startTime );
		$formattedScriptRunTime = $scriptRunTime->format( '%Im %Ss' );

		$output->writeln( "Finished in $formattedScriptRunTime ($formattedTimestamp)" );
	}

	private function removeOldBackups() {
		$backupdirManager = new BackupDirManager( $this->dest, $this->output );
		$backupdirManager->removeOldFiles( $this->wikiName, $this->maxBackupFiles );
	}
}
