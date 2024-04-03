<?php

namespace MWStake\MediaWiki\CliAdm\Tests;

use MWStake\MediaWiki\CliAdm\SettingsReader;
use PHPUnit\Framework\TestCase;

class SettingsReaderTest extends TestCase {
	public function testGetSettingsFromDirectory() {
		$settingsReader = new SettingsReader();
		$settings = $settingsReader->getSettingsFromDirectory( __DIR__ . '/data/SettingsReader' );

		$this->assertEquals( 'MyWikiOverride', $settings['wikiName'] );
		$this->assertEquals( 'localhost', $settings['dbserver'] );
		$this->assertEquals( 'mediawiki', $settings['dbuser'] );
		$this->assertEquals( 'NotSoSecretPassword=', $settings['dbpassword'] );
		$this->assertEquals( null, $settings['dbname'] );
		$this->assertEquals( 'from_setting_d_', $settings['dbprefix'] );
	}
}