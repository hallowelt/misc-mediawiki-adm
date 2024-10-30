<?php

namespace MWStake\MediaWiki\CliAdm\Commands;

use DateTime;
use MWStake\MediaWiki\CliAdm\BlueSpiceFarmInstanceSettingsManager;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;
use Exception;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use MWStake\MediaWiki\CliAdm\IRestoreProfile;
use MWStake\MediaWiki\CliAdm\NullRestoreProfile;
use MWStake\MediaWiki\CliAdm\JSONRestoreProfile;
use MWStake\MediaWiki\CliAdm\DatabaseImporter;
use MWStake\MediaWiki\CliAdm\FilesystemImporter;
use MWStake\MediaWiki\CliAdm\SettingsReader;
use PDO;

class WikiRestore extends Command {

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
	protected $tmpWorkingDir = '';

	/**
	 *
	 * @var string
	 */
	protected $srcFilepath = '';

	/**
	 *
	 * @var string
	 */
	protected $tmpFilepath = '';

	/**
	 *
	 * @var IRestoreProfile
	 */
	protected $profile = null;

	/**
	 *
	 * @var Filesystem
	 */
	protected $filesystem = null;

	/**
	 * @var bool
	 */
	private $isFarmContext = false;

	/**
	 *
	 * @var BlueSpiceFarmInstanceSettingsManager
	 */
	private $farmSettingsManager = null;

	/**
	 *
	 * @var string
	 */
	private $instanceName = '';

	/**
	 *
	 * @var array
	 */
	protected $settings = [];

	protected function configure() {
		parent::configure();
		$this
			->setName( 'wiki-restore' )
			->setDescription( 'Uses a ZIP from "backup-wiki" to restore a wiki instance' )
			->setDefinition( new Input\InputDefinition( [
				new Input\InputOption(
					'mediawiki-root',
					null,
					Input\InputOption::VALUE_REQUIRED,
					'Specifies the diretory, which holds the mediawiki codebase',
					'.'
				),
				new Input\InputOption(
					'src',
					null,
					Input\InputOption::VALUE_REQUIRED,
					'Specifies the backup file to restore'
				),
				new Input\InputOption(
					'profile',
					null,
					Input\InputOption::VALUE_OPTIONAL,
					'Specifies a profile for the import'
				),
				new Input\InputOption(
					'tmp-dir',
					null,
					Input\InputOption::VALUE_OPTIONAL,
					'Specifies the temp work dir path'
				)
			] ) );
	}

	protected function execute( Input\InputInterface $input, OutputInterface $output ) {
		$this->outputStartInfo( $output );
		$this->input = $input;
		$this->output = $output;

		$this->mediawikiRoot = $input->getOption( 'mediawiki-root' );
		$this->filesystem = new Filesystem();

		$this->srcFilepath = $input->getOption( 'src' );
		$this->checkSrcFilepath();
		$this->loadProfile( $input->getOption( 'profile' ) );

		$this->makeTempWorkDir( $input->getOption( 'tmp-dir' ) );
		$this->loadSourceIntoWorkDir();
		$this->extractSourceIntoWorkDir();
		$this->removeSourceFromWorkDir();
		$this->readWikiConfig();
		$this->importFilesystem();
		$this->importDatabase();
		if ( $this->isFarmContext ) {
			$this->farmSettingsManager->setInstanceSetting( $this->instanceName, 'sfi_status', 'ready' );
		}
		$this->removeTempWorkDir();
		$this->outputEndInfo( $output );
	}

	private function makeTempWorkDir( $option = null ) {
		$this->tmpWorkingDir = sys_get_temp_dir() . '/' . time();
		if ( $option ) {
			$this->tmpWorkingDir = $option;
		}
		$this->output->writeln( "Creating '{$this->tmpWorkingDir}'" );
		$this->filesystem->mkdir( $this->tmpWorkingDir, 0777 );
	}

	private function loadSourceIntoWorkDir() {
		$filename = basename( $this->srcFilepath );
		$this->tmpFilepath = $this->tmpWorkingDir . '/' . $filename;
		$this->output->writeln( "Loading '{$this->srcFilepath}' into '{$this->tmpFilepath}'" );
		copy( $this->srcFilepath, $this->tmpFilepath );
	}

	private function extractSourceIntoWorkDir() {
		$zip = new ZipArchive;
		$success = $zip->open( $this->tmpFilepath );
		if ( $success === true) {
			$zip->extractTo( $this->tmpWorkingDir );
			$zip->close();
			$this->output->writeln( "Files extracted" );
		} else {
			throw new Exception( "FAILURE extracting files" );
		}
	}

	private function removeSourceFromWorkDir() {
		$this->filesystem->remove( $this->tmpFilepath );
	}

	private function removeTempWorkDir() {
		//because $this->filesystem->remove is broken and tries to delete the
		//parents first
		$flags = \FilesystemIterator::SKIP_DOTS;
		$deleteIterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->tmpWorkingDir, $flags ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach( $deleteIterator as $file ) {
			try {
				$this->filesystem->remove( $file );
			} catch( Exception $e ) {
				error_log( $e->getMessage() );
			}
		}
		// try again just for good messure... but still the directory will
		// not be removed after that
		$this->filesystem->remove( $this->tmpWorkingDir );
	}

	private function importFilesystem() {
		$filesystemImporter = new FilesystemImporter(
			$this->filesystem,
			$this->input,
			$this->output,
			$this->profile->getFSImportOptions()
		);
		$filesytemPath = "{$this->tmpWorkingDir}/filesystem";
		$filesystemImporter->importDirectory(
			$this->mediawikiRoot,
			$filesytemPath
		);
	}

	private function importDatabase() {
		$pdo = $this->makePDO();
		$databaseImporter = new DatabaseImporter(
			$pdo,
			$this->input,
			$this->output,
			$this->profile->getDBImportOptions()
		);
		$dumpfilepath = "{$this->tmpWorkingDir}/database.sql";
		$databaseImporter->importFile( $dumpfilepath );
	}

	private function checkSrcFilepath() {
		if( $this->srcFilepath === null ) {
			throw new Exception( "Parameter --src must be set!" );
		}

		$srcFile = new SplFileInfo( $this->srcFilepath );
		if( !$srcFile->isFile() ) {
			throw new Exception( "Provided source file is not valid!" );
		}
		if( strtolower( $srcFile->getExtension() ) !== 'zip' ) {
			throw new Exception( "Provided source file is not a ZIP file!" );
		}
	}

	private function loadProfile( $profileFilepath ) {
		if( $profileFilepath === null ) {
			$this->profile = new NullRestoreProfile();
			return;
		}

		$profileFile = new SplFileInfo( $profileFilepath );
		$extension = strtolower( $profileFile->getExtension() );

		if( $extension === 'json') {
			$this->profile = new JSONRestoreProfile( $profileFile->getPathname() );
			return;
		}
		$this->profile = new NullRestoreProfile();
	}

	private function readWikiConfig() {
		$settingsReader = new SettingsReader();
		$this->settings = $settingsReader->getSettingsFromDirectory(
			"{$this->tmpWorkingDir}/filesystem"
		);
		$this->setupFarmEnvironment();
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	private function setupFarmEnvironment() {
		$instanceSettingsFile = "$this->tmpWorkingDir/filesystem/settings.json";
		if ( !file_exists( $instanceSettingsFile ) ) {
			return;
		}
		$mainDbConnectionOptions = $this->profile->getDBImportOptions()['connection'] ?? null;
		if ( !$mainDbConnectionOptions ) {
			throw new Exception(
				"No main database connection options found in profile, but it's required when in farm context"
			);
		}
		$host = $mainDbConnectionOptions['dbserver'] ?? 'localhost';
		$mainPdo = new PDO(
			"mysql:host=$host;dbname={$mainDbConnectionOptions['dbname']}",
			$mainDbConnectionOptions['dbuser'],
			$mainDbConnectionOptions['dbpassword']
		);
		$settingsTable = ( $mainDbConnectionOptions['dbprefix' ] ?? '' ) . 'simple_farmer_instances';
		$this->farmSettingsManager = new BlueSpiceFarmInstanceSettingsManager( $mainPdo, $settingsTable );
		$settings = $this->farmSettingsManager->getSettingsFromFile( $instanceSettingsFile );
		if ( !$settings ) {
			throw new Exception( "Failed to read settings.json for farm instance" );
		}
		$farmOptions = $this->profile->getOptions()['bluespice-farm-options'] ?? null;
		$instancesDir = $farmOptions['instances-dir'] ?? $this->mediawikiRoot . '/_sf_instances';
		$this->instanceName = $settings['path'];
		$this->settings['dbname'] = $settings['dbname'];
		$this->settings['dbserver'] = $mainDbConnectionOptions['dbserver'];
		$this->settings['dbuser'] = $mainDbConnectionOptions['dbuser'];
		$this->settings['dbpassword'] = $mainDbConnectionOptions['dbpassword'];
		$this->settings['dbprefix'] = $settings['dbprefix'];
		$this->settings['wikiName'] = $settings['displayName'];
		$this->mediawikiRoot = $instancesDir . '/' . $settings['path'];
		if ( !$this->farmSettingsManager->assertInstanceEntryInitializedFromFile( $this->instanceName, $instanceSettingsFile ) ) {
			throw new Exception( "Failed to init farm entry" );
		}
	}

	/**
	 *
	 * @return PDO
	 */
	private function makePDO() {
		$connection = [
			'dbserver' => $this->settings['dbserver'],
			'dbname' => $this->settings['dbname'],
			'dbuser' => $this->settings['dbuser'],
			'dbpassword' => $this->settings['dbpassword']
		];

		$dsn = "mysql:host={$connection['dbserver']}";
		$pdo = new PDO( $dsn, $connection['dbuser'], $connection['dbpassword'] );
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		$dbname = "`" . str_replace( "`", "``", $connection['dbname']) . "`";
		$pdo->query("CREATE DATABASE IF NOT EXISTS $dbname");
		$pdo->query("use $dbname");

		return $pdo;
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

		$output->writeln( "\nFinished in $formattedScriptRunTime ($formattedTimestamp)" );
	}

}