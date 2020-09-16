<?php

namespace MWStake\MediaWiki\CliAdm\Commands;

use DateTime;
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
	 *
	 * @var array
	 */
	protected $settings = [];

	protected function configure() {
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
		parent::configure();
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

		$content = file_get_contents( $this->srcFilepath );
		file_put_contents( $this->tmpFilepath, $content );
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
			$this->profile->getOptions()
		);
		$importOptions = $this->profile->getFSImportOptions();
		$filesytemPath = "{$this->tmpWorkingDir}/filesystem";
		$filesystemImporter->importDirectory(
			$this->mediawikiRoot,
			$filesytemPath,
			$importOptions
		);
	}

	private function importDatabase() {
		$pdo = $this->makePDO();
		$databaseImporter = new DatabaseImporter(
			$pdo,
			$this->input,
			$this->output,
			$this->profile->getOptions()
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
	}

	/**
	 *
	 * @return PDO
	 */
	private function makePDO() {
		$dsn = "mysql:host={$this->settings['dbserver']};dbname={$this->settings['dbname']}";
		return new PDO( $dsn, $this->settings['dbuser'], $this->settings['dbpassword'] );
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

}