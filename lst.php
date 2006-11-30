<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();
/**#@+
 * A parser extension that adds two functions, #lst and #lstx, and the 
 * <section> tag, for transcluding marked sections of text.
 *
 * @package MediaWiki
 * @subpackage Extensions
 *
 * @link http://www.mediawiki.org/wiki/Extension:Labeled_Section_Transclusion Documentation
 *
 * @bug 5881
 *
 * @author Steve Sanbeg
 * @copyright Copyright © 2006, Steve Sanbeg
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

##
# Standard initialisation code
##

$wgExtensionFunctions[]="wfLabeledSectionTransclusion";
$wgHooks['LanguageGetMagic'][]       = 'wfLabeledSectionTransclusionMagic';

$wgExtensionCredits['parserhook'][] = array(
        'name' => 'LabeledSectionTransclusion',
        'author' => 'Steve Sanbeg',
        'description' => 'adds #lst and #lstx functions and &lt;section&gt; tag, enables marked sections of text to be transcluded',
        'url' => 'http://www.mediawiki.org/wiki/Extension:Labeled_Section_Transclusion'
        );
$wgParserTestFiles[] = dirname( __FILE__ ) . "/lstParserTests.txt";

function wfLabeledSectionTransclusion() 
{
  global $wgParser, $wgVersion, $wgHooks;
  
  $wgParser->setHook( 'section', 'wfLstNoop' );
  $wgParser->setFunctionHook( 'lst', 'wfLstInclude' );
  $wgParser->setFunctionHook( 'lstx', 'wfLstExclude' );
}

function wfLabeledSectionTransclusionMagic( &$magicWords, $langCode ) {
  // Add the magic words
  $magicWords['lst'] = array( 0, 'lst' );
  $magicWords['lstx'] = array( 0, 'lstx' );
  return true;
}

##############################################################
# To do transclusion from an extension, we need to interact with the parser
# at a low level.  This is the general transclusion functionality
##############################################################

///Register what we're working on in the parser, so we don't fall into a trap.
function wfLst_open_(&$parser, $part1) 
{
  // Infinite loop test
  if ( isset( $parser->mTemplatePath[$part1] ) ) {
    wfDebug( __METHOD__.": template loop broken at '$part1'\n" );
    return false;
  } else {
    $parser->mTemplatePath[$part1] = 1;
    return true;
  }
  
}

///Finish processing the function.
function wfLst_close_(&$parser, $part1) 
{
  // Infinite loop test
  if ( isset( $parser->mTemplatePath[$part1] ) ) {
    unset( $parser->mTemplatePath[$part1] );
  } else {
    wfDebug( __METHOD__.": close unopened template loop at '$part1'\n" );
  }
}

///Fetch the page to be transcluded from the database.
function wfLst_fetch_(&$parser, $page, $ns = NS_MAIN) 
{
  global $wgContLang;
  $subpage = '';
  $text = false;
  
  $page = $parser->maybeDoSubpageLink($page,$subpage);
  if ($subpage !== '') {
    $ns = $parser->mTitle->getNamespace();
  }
  $title = Title::newFromText($page,$ns);
  if ( !is_null( $title ) ) {
    //Check for language variants if the template is not found
    $checkVariantLink = sizeof($wgContLang->getVariants())>1;
    if($checkVariantLink && $title->getArticleID() == 0){
      $wgContLang->findVariantLink($page, $title);
    }
    if ( $title->isTrans() ) {
      // Interwiki transclusion - has to be raw, since section markers
      // aren't rendered.
      $text = $parser->interwikiTransclude( $title, 'raw' );
    }
    $text = $parser->fetchTemplate($title);
  }
  
  return $text;
}

/**
 * Handle recursive substitution here, so we can break cycles, and set up
 * return values so that edit sections will resolve correctly.
 **/
function wfLst_parse_(&$parser, $title, $text, $part1) 
{
  if (wfLst_open_($parser, $part1)) {
    //handle recursion here, so we can break cycles.
    $text = $parser->replaceVariables($text);
    wfLst_close_($parser, $part1);
    //Try to get edit sections correct by munging around the parser's guts.
    return array($text, 'title'=>$title, 'replaceHeadings'=>true);
  }  else {
    return "[[" . $title->getPrefixedText() . "]]". 
      "<!-- WARNING: LST loop detected -->";
  }
  
}

##############################################################
# And now, the labeled section transclusion
##############################################################

///The section markers aren't paired, so we only need to remove them.
function wfLstNoop( $in, $assocArgs=array(), $parser=null ) {
  return '';
}

///Generate a regex to match the section(s) we're interested in.
function wfLst_pat_($sec, $to) 
{
  $to_sec = ($to == '')?$sec : $to;
  $sec = preg_quote($sec, '/');
  $to_sec = preg_quote($to_sec, '/');
  
  return '/<section\s+begin='.
    "(?:$sec|\"$sec\"|'$sec')".
    '\s*\/?>(.*?)\n?<section\s+end='.
    "(?:$to_sec|\"$to_sec\"|'$to_sec')".
    '\s*\/?>/s';
}

///section inclusion - include all matching sections
function wfLstInclude(&$parser, $page='', $sec='', $to='')
{
  global $wgHooks;
  
  $pat = wfLst_pat_($sec,$to);
  $title = Title::newFromText($page);

  if (is_null($title) )
    return '';
  
  $text = wfLst_fetch_($parser,$page);
  
  //if article doesn't exist, return a red link.
  if ($text == false)
    return "[[" . $title->getPrefixedText() . "]]";
  
  preg_match_all( $pat, $text, $m);
  $text = '';
  foreach ($m[1] as $piece)  {
    $text .= $piece;
  }

  return wfLst_parse_($parser,$title,$text, "#lst:${page}|${sec}");
}
  
///section exclusion, with optional replacement
function wfLstExclude(&$parser, $page='', $sec='', $repl='',$to='')
{
  $pat = wfLst_pat_($sec,$to);
  $title = Title::newFromText($page);

  if (is_null($title))
    return '';
  
  $text = wfLst_fetch_($parser,$page);
  
  //if article doesn't exist, return red link.
  if ($text == false)
    return "[[" . $title->getPrefixedText() . "]]";
  
  $text = preg_replace( $pat, $repl, $text);

  return wfLst_parse_($parser,$title,$text, "#lstx:$page|$sec");
}

?>
