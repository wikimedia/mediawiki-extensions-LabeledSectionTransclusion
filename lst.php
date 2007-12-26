<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();
/**#@+
 * A parser extension that adds two functions, #lst and #lstx, and the 
 * <section> tag, for transcluding marked sections of text.
 *
 * @addtogroup Extensions
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
  global $wgParser;
  
  $wgParser->setHook( 'section', 'wfLstNoop' );
  $wgParser->setFunctionHook( 'lst', 'wfLstInclude' );
  $wgParser->setFunctionHook( 'lstx', 'wfLstExclude' );
}

/// Add the magic words - possibly with more readable aliases
function wfLabeledSectionTransclusionMagic( &$magicWords, $langCode ) {
  global $wgParser, $wgLstLocal;

  switch( $langCode ) {
  case 'de':
    $include = 'Abschnitt';
    $exclude = 'Abschnitt-x';
    $wgLstLocal = array( 'section' => 'Abschnitt', 'begin' => 'Anfang', 'end' => 'Ende') ;
  break;
  case 'he':
    $include = 'קטע';
    $exclude = 'בלי קטע';
    $wgLstLocal = array( 'section' => 'קטע', 'begin' => 'התחלה', 'end' => 'סוף') ;
  break;
  case 'pt':
    $include = 'trecho';
    $exclude = 'trecho-x';
    $wgLstLocal = array( 'section' => 'trecho', 'begin' => 'começo', 'end' => 'fim');
    break;
  }
  
  if( isset( $include ) ) {
    $magicWords['lst'] = array( 0, 'lst', 'section', $include );
    $magicWords['lstx'] = array( 0, 'lstx', 'section-x', $exclude );
    $wgParser->setHook( $include, 'wfLstNoop' );
  } else {
    $magicWords['lst'] = array( 0, 'lst', 'section' );
    $magicWords['lstx'] = array( 0, 'lstx', 'section-x' );
  }
  
  return true;
}

##############################################################
# To do transclusion from an extension, we need to interact with the parser
# at a low level.  This is the general transclusion functionality
##############################################################

///Register what we're working on in the parser, so we don't fall into a trap.
function wfLst_open_($parser, $part1) 
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
function wfLst_close_($parser, $part1) 
{
  // Infinite loop test
  if ( isset( $parser->mTemplatePath[$part1] ) ) {
    unset( $parser->mTemplatePath[$part1] );
  } else {
    wfDebug( __METHOD__.": close unopened template loop at '$part1'\n" );
  }
}

/**
 * Handle recursive substitution here, so we can break cycles, and set up
 * return values so that edit sections will resolve correctly.
 * @param Parser $parser
 * @param Title $title of target page
 * @param string $text
 * @param string $part1 Key for cycle detection
 * @param int $skiphead Number of source string headers to skip for numbering
 * @return mixed string or magic array of bits
 * @todo handle mixed-case </section>
 * @private
 */
function wfLst_parse_($parser, $title, $text, $part1, $skiphead=0) 
{
  // if someone tries something like<section begin=blah>lst only</section>
  // text, may as well do the right thing.
  $text = str_replace('</section>', '', $text);

  if (wfLst_open_($parser, $part1)) {
    //Handle recursion here, so we can break cycles.
    global $wgVersion;
    if( version_compare( $wgVersion, "1.9" ) < 0 ) {
      $text = $parser->replaceVariables($text);
      wfLst_close_($parser, $part1);
    }
    
    //Try to get edit sections correct by munging around the parser's guts.
    return array($text, 'title'=>$title, 'replaceHeadings'=>true, 
		 'headingOffset'=>$skiphead, 'noparse'=>false, 'noargs'=>false);
  }  else {
    return "[[" . $title->getPrefixedText() . "]]". 
      "<!-- WARNING: LST loop detected -->";
  }
  
}

##############################################################
# And now, the labeled section transclusion
##############################################################

/**
 * Parser tag hook for <section>.
 * The section markers aren't paired, so we only need to remove them.
 *
 * @param string $in
 * @param array $assocArgs
 * @param Parser $parser
 * @return string HTML output
 */
function wfLstNoop( $in, $assocArgs=array(), $parser=null ) {
  return '';
}

/**
 * Generate a regex to match the section(s) we're interested in.
 * @param string $sec Name of target section
 * @param string $to Optional name of section to end with, if transcluding
 *                   multiple sections in sequence. If blank, will assume
 *                   same section name as started with.
 * @return string regex
 * @private
 */
function wfLst_pat_($sec, $to) 
{
  global $wgLstLocal;
  
  $to_sec = ($to == '')?$sec : $to;
  $sec = preg_quote($sec, '/');
  $to_sec = preg_quote($to_sec, '/');
  $ws="(?:\s+[^>]+)?"; //was like $ws="\s*"
  if (isset($wgLstLocal)){
    $begin="(?i:begin|$wgLstLocal[begin])";
    $end="(?i:end|$wgLstLocal[end])";
    $section_re = "(?i:section|$wgLstLocal[section])";
  } else {
    $begin="(?i:begin)";
    $end="(?i:end)";
    $section_re = "(?i:section)";
  }
  
  return "/<$section_re$ws\s+$begin=".
    "(?:$sec|\"$sec\"|'$sec')".
    "$ws\/?>(.*?)\n?<$section_re$ws\s+(?:[^>]+\s+)?$end=".
    "(?:$to_sec|\"$to_sec\"|'$to_sec')".
    "$ws\/?>/s";
}

/**
 * Count headings in skipped text.
 *
 * Count skipped headings, so parser (as of r18218) can skip them, to
 * prevent wrong heading links (see bug 6563).
 *
 * @param string $text
 * @param int $limit Cutoff point in the text to stop searching
 * @return int Number of matches
 * @private
 */
function wfLst_count_headings_($text,$limit) 
{
  $pat = '^(={1,6}).+\1\s*$()';
  
  //return preg_match_all( "/$pat/im", substr($text,0,$limit), $m);

  $count = 0;
  $offset = 0;
  while (preg_match("/$pat/im", $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
    if ($m[2][1] > $limit)
      break;

    $count++;
    $offset = $m[2][1];
  }

  return $count;
}

/**
 * Fetches content of target page if valid and found, otherwise
 * produces wikitext of a link to the target page.
 *
 * @param Parser $parser
 * @param string $page title text of target page
 * @param (out) Title $title normalized title object
 * @param (out) string $text wikitext output
 * @return string bool true if returning text, false if target not found
 * @private
 */
function wfLst_text_($parser, $page, &$title, &$text) 
{
  $title = Title::newFromText($page);
  
  if (is_null($title) ) {
    $text = '';
    return true;
  } else {
    if (method_exists($parser, 'fetchTemplateAndTitle')) {
      list($text,$title) = $parser->fetchTemplateAndTitle($title);
    } else {
      $text = $parser->fetchTemplate($title);
    }
  }
  
  //if article doesn't exist, return a red link.
  if ($text == false) {
    $text = "[[" . $title->getPrefixedText() . "]]";
    return false;
  } else {
    return true;
  }
}

/**
 * Parser function hook for '#lst:'
 * section inclusion - include all matching sections
 *
 * @param Parser $parser
 * @param string $page Title text of target page
 * @param string $sec Named section to transclude
 * @param string $to Optional named section to end at
 * @return mixed wikitext output
 */
function wfLstInclude($parser, $page='', $sec='', $to='')
{
  if (wfLst_text_($parser, $page, $title, $text) == false)
    return $text;
  $pat = wfLst_pat_($sec,$to);

  if(preg_match_all( $pat, $text, $m, PREG_OFFSET_CAPTURE)) {
    $headings = wfLst_count_headings_($text, $m[0][0][1]);
  } else {
    $headings = 0;
  }
  
  $text = '';
  foreach ($m[1] as $piece)  {
    $text .= $piece[0];
  }

  //wfDebug("wfLstInclude: skip $headings headings");
  return wfLst_parse_($parser,$title,$text, "#lst:${page}|${sec}", $headings);
}

/**
 * Parser function hook for '#lstx:'
 * section exclusion, with optional replacement
 *
 * @param Parser $parser
 * @param string $page Title text of target page
 * @param string $sec Named section to transclude
 * @param string $repl Optional wikitext to use to fill in the excluded section
 * @param string $to Optional named section to end at
 * @return mixed wikitext output
 */
function wfLstExclude($parser, $page='', $sec='', $repl='',$to='')
{
  if (wfLst_text_($parser, $page, $title, $text) == false)
    return $text;
  $pat = wfLst_pat_($sec,$to);
  $text = preg_replace( $pat, $repl, $text);
  return wfLst_parse_($parser,$title,$text, "#lstx:$page|$sec");
}

