<?php

namespace MWStake\MediaWiki\CliAdm\Commands;

use DateTime;
use Exception;
use MWStake\MediaWiki\CliAdm\FarmInstanceSettingsManager;
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

	/** @var bool */
	private $isFarmContext = false;
	/** @var string */
	private $instanceName;

	/** @var array */
	private $skipDbPrefixes = [];

	/** @var FarmInstanceSettingsManager|null */
	private $farmSettingsReader = null;

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
		$this->doBackup();

		$this->output->writeln( '<info>--> Done.</info>' );
		$this->outputEndInfo( $output );
	}

	private function doBackup() {
		if ( $this->isFarmContext ) {
			$this->output->writeln( "<info> --> Backing up farm instance $this->instanceName</info>" );
		}
		$this->checkDatabaseConnection();
		$this->initZipFile();
		$this->addSettingsFiles();
		$this->addImagesFolder();
		$this->addCustomFilesAndFolders();
		$this->dumpDatabase();

		$this->zip->close();
		$this->cleanUp();

		$this->removeOldBackups();
	}

	protected $dbname = '';
	protected $dbuser = '';
	protected $dbpassword = '';
	protected $dbserver = '';
	protected $dbprefix = '';

	protected function readInSettingsFile() {
		$profileData = $this->profile->getDBBackupOptions();
		if ( isset( $profileData['connection'] ) ) {
			$connection = $profileData['connection'];
			$this->dbname = $connection['dbname'] ?? null;
			$this->dbuser = $connection['dbuser'] ?? null;
			$this->dbpassword = $connection['dbpassword'] ?? null;
			$this->dbserver = $connection['dbserver'] ?? null;
			$this->dbprefix = $connection['dbprefix'] ?? null;
		}

		$settingsReader = new SettingsReader();
		$settings = $settingsReader->getSettingsFromDirectory( $this->mediawikiRoot );

		$requiredFields = [
			'dbname', 'dbpassword', 'dbuser', 'dbserver', 'dbprefix'
		];

		foreach( $requiredFields as $requiredField ) {
			if ( $this->{$requiredField} ) {
				// Already set by the provided backup-profile
				continue;
			}
			if( !isset( $settings[$requiredField] ) ) {
				throw new \Exception( "Required information '$requiredField' "
						. "could not be extracted!" );
			}
			$this->{$requiredField} = $settings[$requiredField];
		}
		$farmOptions = $this->profile->getFarmOptions();
		if ( $farmOptions && $farmOptions['instance-name'] ) {
			$this->setupFarmEnvironment( $farmOptions );
		}
	}

	private function setupFarmEnvironment( array $options ) {
		$this->output->writeln( "Setting up farm environment ..." );
		$this->isFarmContext = true;
		$this->instanceName = $options['instance-name'];
		$instancesDir = $options['instances-dir'] ??
			rtrim( $this->mediawikiRoot, '/' ) . '/_sf_instances/';

		$settingsTable = 'simple_farmer_instances';
		if ( $this->dbprefix ) {
			$settingsTable = $this->dbprefix . $settingsTable;
		}
		try {
			$mainPdo = new \PDO(
				"mysql:host=$this->dbserver;dbname=$this->dbname",
				$this->dbuser,
				$this->dbpassword
			);
		} catch( \PDOException $ex ) {
			throw new Exception( "Could not connect to management database: " . $ex->getMessage() );
		}

		$this->farmSettingsReader = new FarmInstanceSettingsManager( $mainPdo, $settingsTable );
		if ( $this->instanceName === '*' ) {
			$this->output->writeln( "Backing up all instances ..." );
			// Backup all instances
			$mainDbName = $this->dbname;
			$mainDbPrefix = $this->dbprefix;
			$originalMWRoot = $this->mediawikiRoot;
			$activeInstances = $this->farmSettingsReader->getAllActiveInstances();
			foreach ( $activeInstances as $instanceName ) {
				$this->instanceName = $instanceName;
				$this->mediawikiRoot = "$instancesDir/$instanceName";
				if ( $this->setupSingleFarmInstance( $this->farmSettingsReader, $instanceName ) ) {
					try {
						$this->doBackup();
					} catch ( Exception $ex ) {
						$this->output->writeln( "Error backing up instance $instanceName: " . $ex->getMessage() );
					}

				} else {
					$this->output->writeln( "Skipping instance $instanceName, cannot read settings" );
				}
			}
			$this->output->writeln( "<info> --> Backing up main instance ...</info>" );
			// Backup main instance at the end
			$this->dbname = $mainDbName;
			$this->dbprefix = $mainDbPrefix;
			$this->mediawikiRoot = $originalMWRoot;
			$this->isFarmContext = false;
			$this->skipDbPrefixes = $this->farmSettingsReader->getAllInstancePrefixes( $this->dbname );
		} else {
			$this->mediawikiRoot = "$instancesDir/$this->instanceName";
			if ( !$this->setupSingleFarmInstance( $this->instanceName ) ) {
				throw new Exception( "Could not read settings for instance '$this->instanceName'" );
			}
		}
	}

	/**
	 * @param string $instanceName
	 * @return bool
	 */
	private function setupSingleFarmInstance( string $instanceName ) {
		$settings = $this->farmSettingsReader->getSettings( $instanceName );
		if ( !$settings ) {
			return false;
		}
		$this->dbname = $settings['dbname'];
		$this->dbprefix = $settings['dbprefix'];
		return true;
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

		$targetFilename = $this->getTargetFilename();
		return "{$this->dest}/{$targetFilename}{$suffix}.zip";
	}

	/**
	 * @return string
	 */
	protected function getTargetFilename() {
		if ( $this->isFarmContext ) {
			return $this->instanceName;
		}
		$default = $this->dbname . ( $this->dbprefix ? "-{$this->dbprefix}" : '' );
		return $this->profile->getOption( 'target-filename', $default );
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

	private function addCustomFilesAndFolders() {
		$toBackup = $this->getCustomFilesToBackup();
		$backupCount = count( $toBackup );
		if ( $this->isFarmContext ) {
			$backupCount++;
			$settings =  $this->farmSettingsReader->getFullSettings( $this->instanceName );
			if ( $settings === null ) {
				throw new Exception( "Could not read settings for instance '$this->instanceName'" );
			}
			$res = file_put_contents(
				$this->mediawikiRoot . '/settings.json',
				json_encode( $settings, JSON_PRETTY_PRINT )
			);
			if ( !$res ) {
				throw new Exception( "Could not write instance settings file" );
			}
			$toBackup[] = $this->mediawikiRoot . '/settings.json';
		}

		$progressBar = new ProgressBar( $this->output, $backupCount );
		$this->output->writeln( "Adding 'custom-paths' ..." );
		foreach( $toBackup as $customFile ) {
			$localPath = preg_replace( '#^' . preg_quote( $this->mediawikiRoot ) . '#', '', $customFile );
			$this->zip->addFile( $customFile, "filesystem/$localPath" );
			$progressBar->advance();
		}
		$progressBar->finish();
		$this->output->write( "\n" );
	}

	private function getCustomFilesToBackup(): array {
		$filesystemOptions = $this->profile->getFSBackupOptions();
		$customPaths = $filesystemOptions['include-custom-paths'] ?? [];
		if ( empty( $customPaths )	) {
			return [];
		}
		$customFilesToBackup = [];
		foreach( $customPaths as $customPath ) {
			$customPath = "{$this->mediawikiRoot}/$customPath";
			if( !file_exists( $customPath ) ) {
				$this->output->writeln( "Warning: Custom path '$customPath' does not exist!" );
				continue;
			}
			if( is_file( $customPath ) ) {
				$customFilesToBackup[] = $customPath;
				continue;
			}
			$customDirectoryIterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $customPath )
			);
			foreach( $customDirectoryIterator as $fileInfo ) {
				if( $fileInfo->isDir() ) {
					continue;
				}
				$customFilesToBackup[] = $fileInfo->getPathname();
			}
		}
		return $customFilesToBackup;
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
			'skip-definer' => true,
		];
		if ( !empty( $this->dbprefix ) ) {
			$dumpSettings['include-tables'] = $this->getTablesWithPrefix( [ $this->dbprefix ] );
		}
		$dumpSettings['exclude-tables'] = array_merge(
			$dumpSettings['exclude-tables'] ?? [],
			$this->getTablesWithPrefix( $this->skipDbPrefixes, true )
		);

		// TODO: Dump only with given prefix
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
		$backupdirManager->removeOldFiles( $this->getTargetFilename(), $this->maxBackupFiles );
	}

	/**
	 * @param array $prefixes
	 * @param bool $includeViews
	 * @return array
	 */
	private function getTablesWithPrefix( array $prefixes, bool $includeViews = false ): array {
		$tables = [];
		$pdo = new \PDO(
			"mysql:host={$this->dbserver};dbname={$this->dbname}",
			$this->dbuser,
			$this->dbpassword
		);
		$query = "SHOW FULL TABLES FROM `{$this->dbname}`";
		if ( !$includeViews ) {
			$query .= "  WHERE TABLE_TYPE NOT LIKE 'VIEW'";
		}

		$res = $pdo->query( $query );
		while ( $row = $res->fetch() ) {
			$tableName = $row[0];
			foreach ( $prefixes as $prefix ) {
				if ( strpos( $tableName, $prefix ) === 0 ) {
					$tables[] = $tableName;
					break;
				}
			}
		}
		return $tables;

	}
}
