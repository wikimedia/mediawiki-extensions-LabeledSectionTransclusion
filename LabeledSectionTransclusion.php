<?php

/**#@+
 * A parser extension that adds two functions, #lst and #lstx, and the
 * <section> tag, for transcluding marked sections of text.
 *
 * @file
 * @ingroup Extensions
 *
 * @link https://www.mediawiki.org/wiki/Extension:Labeled_Section_Transclusion Documentation
 *
 * @bug 5881
 *
 * @author Steve Sanbeg
 * @copyright Copyright © 2006, Steve Sanbeg
 * @license GPL-2.0-or-later
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'LabeledSectionTransclusion' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['LabeledSectionTransclusion'] = __DIR__ . '/i18n';
	wfWarn(
		'Deprecated PHP entry point used for LabeledSectionTransclusion extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the LabeledSectionTransclusion extension requires MediaWiki 1.25+' );
}
