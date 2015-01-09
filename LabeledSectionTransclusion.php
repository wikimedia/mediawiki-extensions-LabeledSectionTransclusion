<?php
if ( ! defined( 'MEDIAWIKI' ) ) {
	die();
}

/**#@+
 * A parser extension that adds two functions, #lst and #lstx, and the
 * <section> tag, for transcluding marked sections of text.
 *
 * @file
 * @ingroup Extensions
 *
 * @link http://www.mediawiki.org/wiki/Extension:Labeled_Section_Transclusion Documentation
 *
 * @bug 5881
 *
 * @author Steve Sanbeg
 * @copyright Copyright © 2006, Steve Sanbeg
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

$wgHooks['ParserFirstCallInit'][] = 'LabeledSectionTransclusion::setup';
// @todo FIXME: LanguageGetMagic is obsolete, but LabeledSectionTransclusion::setupMagic()
//              contains magic hack that $magicWords cannot handle.
$wgHooks['LanguageGetMagic'][] = 'LabeledSectionTransclusion::setupMagic';

$wgExtensionCredits['parserhook'][] = array(
	'path'           => __FILE__,
	'name'           => 'LabeledSectionTransclusion',
	'author'         => 'Steve Sanbeg',
	'url'            => 'https://www.mediawiki.org/wiki/Extension:Labeled_Section_Transclusion',
	'descriptionmsg' => 'lst-desc',
);

$dir = __DIR__;
$wgAutoloadClasses['LabeledSectionTransclusion'] = $dir . '/LabeledSectionTransclusion.class.php';
$wgParserTestFiles[] = $dir . '/lstParserTests.txt';
$wgParserTestFiles[] = $dir . '/lstIncorrectParserTest.txt';
$wgParserTestFiles[] = $dir . '/lsthParserTests.txt';
$wgMessagesDirs['LabeledSectionTransclusion'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['LabeledSectionTransclusion'] = $dir . '/lst.i18n.php';

$wgLstLocal = null;
