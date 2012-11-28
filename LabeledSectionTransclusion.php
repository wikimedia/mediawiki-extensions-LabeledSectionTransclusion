<?php
# This is a conventional entry point for continuous integration.
# Jenkins job expect a PHP file having the same name as the git
# repository hosting the extension. Aka:
#
if( !defined('MEDIAWIKI') ) {
	die();
}

require_once( 'lst.php'  );
require_once( 'lsth.php' );
