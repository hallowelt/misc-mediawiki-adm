<?php

namespace MWStake\MediaWiki\CliAdm\Commands;

use DateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use ZipArchive;
use Ifsnop\Mysqldump\Mysqldump;
use MWStake\MediaWiki\CliAdm\SettingsReader;
use MWStake\MediaWiki\CliAdm\SettingsFileIterator;

class WikiBackup extends Command {

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
	 * @var ZipArchive
	 */
	protected $zip = null;

	protected function configure() {
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
				)
			] ) );

		return parent::configure();
	}

	protected function execute( Input\InputInterface $input, OutputInterface $output ) {
		$this->outputStartInfo( $output );
		$this->input = $input;
		$this->output = $output;

		$this->mediawikiRoot = $input->getOption( 'mediawiki-root' );
		$this->dest = realpath( $input->getOption( 'dest' ) );
		$this->omitTimestamp = $input->getOption( 'omit-timestamp' );

		$this->readInSettingsFile();
		$this->initZipFile();
		$this->addSettingsFiles();
		$this->addImagesFolder();
		$this->dumpDatabase();

		$this->zip->close();
		$this->cleanUp();

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
		$settingsReader = new SettingsReader();
		$settings = $settingsReader->getSettingsFromDirectory( $this->mediawikiRoot );

		$requiredFields = [
			'dbname', 'dbpassword', 'dbuser', 'dbserver', 'wikiName'
		];

		foreach( $requiredFields as $requiredField ) {
			if( empty( $settings[$requiredField] ) ) {
				throw new \Exception( "Required information '$requiredField' "
						. "could not be extracted!" );
			}
			$this->{$requiredField} = $settings[$requiredField];
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

	protected $skipFolders = [ 'thumb', 'temp', 'cache' ];

	protected function addImagesFolder() {
		$imagesDir = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator(
					"{$this->mediawikiRoot}/images"
				)
		);
		$imagesToBackup = [];
		$this->output->writeln( "Adding 'images/' ..." );

		foreach( $imagesDir as $fileInfo ) {
			$fileInfo instanceof \SplFileInfo;
			if( $fileInfo->isDir() ) {
				continue;
			}
			$blacklisted = false;
			foreach( $this->skipFolders as $folder ) {
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

	protected $skipTables = [ 'objectcache', 'l10n_cache', 'bs_whoisonline', 'smw_.*?' ];

	protected $tmpDumpFilepath = '';

	protected function dumpDatabase() {
		$this->output->writeln(
			"Dumping '{$this->dbserver}/{$this->dbname}' ..."
		);

		$dumpSettings = [
			'add-drop-table' => true,
			'no-data' => array_map( function( $item ) {
				return $this->dbprefix.$item;
			}, $this->skipTables ),
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
