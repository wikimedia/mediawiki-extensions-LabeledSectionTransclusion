<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();

/**#@+ 
 *
 * A parser extension that further extends labeled section transclusion,
 * adding a function, #lsth for transcluding marked sections of text,
 *
 * This calls internal functions from lst.php.  It will not work if that
 * extension is not enabled, and may not work if the two files are not in
 * sync.
 *
 * @package MediaWiki
 * @subpackage Extensions
 *
 * @link http://www.mediawiki.org/wiki/Labeled_Section_Transclusion Documentation
 *
 * @author Steve Sanbeg
 * @copyright Copyright © 2006, Steve Sanbeg
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

##
# Standard initialisation code
##

$wgExtensionFunctions[]="wfLabeledSectionTransclusionHeading";
$wgHooks['LanguageGetMagic'][] = 'wfLabeledSectionTransclusionHeadingMagic';
$wgParserTestFiles[] = dirname( __FILE__ ) . "/lsthParserTests.txt";

function wfLabeledSectionTransclusionHeading() 
{
  global $wgParser;
  $wgParser->setFunctionHook( 'lsth', 'wfLstIncludeHeading2' );
}

function wfLabeledSectionTransclusionHeadingMagic( &$magicWords, $langCode ) {
  // Add the magic words
  $magicWords['lsth'] = array( 0, 'lsth' );
  return true;
}

///section inclusion - include all matching sections
function wfLstIncludeHeading2(&$parser, $page='', $sec='', $to='')
{
  global $wgHooks;
  
  $title = Title::newFromText($page);

  if (is_null($title) )
    return '';
  
  $text = wfLst_fetch_($parser,$page);
  
  //if article doesn't exist, return a red link.
  if ($text == false)
    return "[[" . $title->getPrefixedText() . "]]";

  //Generate a regex to match the === classical heading section(s) === we're
  //interested in.
  if ($sec == '') {
    $begin_off = 0;
    $head_len = 6;
  } else {
    $pat = '^(={1,6})\s*' . preg_quote($sec, '/') . '\s*\1\s*($)' ;
    if ( preg_match( "/$pat/im", $text, $m, PREG_OFFSET_CAPTURE) ) {
      $begin_off = $m[2][1];
      $head_len = strlen($m[1][0]);
      //echo "**offset is $begin_off\n";
    } else {
      //echo "**match failed: '$pat'\n";
      return '';
    }
    
  }

  if ($to != '') {
    //if $to is supplied, try and match it.  If we don't match, just
    //ignore it.
    $pat = '^(={1,6})\s*' . preg_quote($to, '/') . '\s*\1\s*$';
    if (preg_match( "/$pat/im", $text, $m, PREG_OFFSET_CAPTURE, $begin_off))
      $end_off = $m[0][1]-1;
  }


  if (! isset($end_off)) {
    $pat = '^(={1,'.$head_len.'})\s*.*?\1\s*$';
    if (preg_match( "/$pat/im", $text, $m, PREG_OFFSET_CAPTURE, $begin_off))
      $end_off = $m[0][1]-1;
    //else print "**fail end match: '$pat'\n";
    
  } 
  //print "**len is $head_len, end is $end_off\n";

  if (isset($end_off))
    $result = substr($text, $begin_off, $end_off - $begin_off);
  else
    $result = substr($text, $begin_off);
  
  //echo "**text: $begin_off + $end_off\n";

  return wfLst_parse_($parser,$title,$result, "#lsth:${page}|${sec}");
}



?>
