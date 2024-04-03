<?php

$wgSitename = "MyWiki";

$wgDBserver = 'localhost';
$wgDBname = getenv( 'MYSQL_DB_NAME' );
$wgDBuser = 'mediawiki';
$wgDBpassword = 'NotSoSecretPassword=';
#$wgDBpassword = 'SecretPassword';
$wgDBprefix = 'from_localsettings_';
