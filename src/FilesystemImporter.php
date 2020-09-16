<?php

namespace MWStake\MediaWiki\CliAdm;

use Exception;
use FilesystemIterator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FilesystemImporter {

	const OPT_SKIP_PATHS = 'skip-paths';
	const OPT_OVERWRITE_NEWER_FILE = 'overwrite-newer';

	/**
	 *
	 * @var InputInterface
	 */
	private $input = null;

	/**
	 *
	 * @var OutputInterface
	 */
	private $output = null;

	/**
	 *
	 * @var Filesystem
	 */
	private $filesystem = null;

	/**
	 *
	 * @var array
	 */
	private $importOptions = [
		self::OPT_SKIP_PATHS => [],
		self::OPT_OVERWRITE_NEWER_FILE => false,
	];

	/**
	 *
	 * @param Filesystem $filesystem
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param array $importOptions
	 */
	public function __construct( Filesystem $filesystem, InputInterface $input,
		OutputInterface $output, array $importOptions = [] ) {

		$this->input = $input;
		$this->output = $output;
		$this->filesystem = $filesystem;
		foreach( $this->importOptions as $key => $option ) {
			if ( !isset( $importOptions[$key] ) ) {
				continue;
			}
			$this->importOptions[$key] = $importOptions[$key];
		}
	}

	/**
	 *
	 * @param string $destinationPath
	 * @param string $dirPathname
	 * @return void
	 */
	public function importDirectory( $destinationPath, $dirPathname ) {
		if( $this->readyToImport() ) {
			$this->doImport( $destinationPath, $dirPathname );
		}
	}

	private function readyToImport() {
		return true;
	}

	private function doImport( $destinationPath, $dirPathname ) {
		$errorDetect = false;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dirPathname, FilesystemIterator::SKIP_DOTS ),
			false
		);

		$progressBar = new ProgressBar( $this->output );
		foreach( $iterator as $file ) {
			$srcFilePath = str_replace( '\\', '/', $file->getPathname() );
			#$this->output->writeln( $srcFilePath );
			$targetFilePath = $destinationPath . substr(
				$srcFilePath,
				strlen( $dirPathname )
			);
			#$this->output->writeln( "  => $targetFilePath" );
			if( $this->skipCurrentFile( $targetFilePath ) ) {
				#$this->output->writeln( "  ...SKIP" );
				$progressBar->advance();
				continue;
			}
			try {
				$this->filesystem->copy(
					$srcFilePath,
					$targetFilePath,
					$this->importOptions[self::OPT_OVERWRITE_NEWER_FILE]
				);
				#$this->output->writeln( "  ...OK" );
			}
			catch ( Exception $e ) {
				$this->output->writeln(
					"<error>...Error performing copy: {$e->getMessage()}</error>"
				);
				$errorDetect = true;
			}
			$progressBar->advance();
		}

		$progressBar->advance();

		if ($errorDetect) {
			return false;
		}

	}

	private function skipCurrentFile( $targetFilePath ) {
		foreach( $this->importOptions[self::OPT_SKIP_PATHS] as $regex ) {
			if( preg_match( $regex, $targetFilePath ) > 0 ) {
				return true;
			}
		}
		return false;
	}
}