<?php

class LabeledSectionTransclusion {

	/**
	 * MediaWiki supports localisation for the three kinds of magic words,
	 * such as variable {{NAME}}, behaviours __NAME__, and parser functions
	 * {{#name}}, but it does not support localisation of tag hooks, such
	 * as <name>. Work around that limitation by performing the localisation
	 * at run-time when calling Parser::setHook().
	 */
	private static $hookTranslation = [
		'de' => [
			// Tag name
			'section' => 'Abschnitt',
			// Tag attributes
			'begin' => 'Anfang',
			'end' => 'Ende',
		],
		'he' => [
			'section' => 'קטע',
			'begin' => 'התחלה',
			'end' => 'סוף',
		],
		'pt' => [
			'section' => 'trecho',
			'begin' => 'começo',
			'end' => 'fim',
		],
	];

	/**
	 * Get local name for tag or tag attribute (based on content language)
	 * @param string $key
	 * @return string|null
	 */
	private static function getLocalName( $key ) {
		global $wgLanguageCode;
		return self::$hookTranslation[$wgLanguageCode][$key] ?? null;
	}

	private static $loopCheck = [];

	/**
	 * @param Parser $parser
	 * @return bool
	 */
	public static function setup( $parser ) {
		$parser->setHook( 'section', [ __CLASS__, 'noop' ] );
		// Register the localized version of <section> as a noop as well
		$localName = self::getLocalName( 'section' );
		if ( $localName !== null ) {
			$parser->setHook( $localName, [ __CLASS__, 'noop' ] );
		}
		$parser->setFunctionHook( 'lst', [ __CLASS__, 'pfuncIncludeObj' ], Parser::SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'lstx', [ __CLASS__, 'pfuncExcludeObj' ], Parser::SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'lsth', [ __CLASS__, 'pfuncIncludeHeading' ] );

		return true;
	}

	/*
	 * To do transclusion from an extension, we need to interact with the parser
	 * at a low level. This is the general transclusion functionality
	 */

	/**
	 * Register what we're working on in the parser, so we don't fall into a trap.
	 * @param Parser $parser
	 * @param string $part1
	 * @return bool
	 */
	private static function open_( $parser, $part1 ) {
		// Infinite loop test
		if ( isset( $parser->mTemplatePath[$part1] ) ) {
			wfDebug( __METHOD__ . ": template loop broken at '$part1'\n" );
			return false;
		} else {
			$parser->mTemplatePath[$part1] = 1;
			return true;
		}
	}

	/**
	 * Finish processing the function.
	 * @param Parser $parser
	 * @param string $part1
	 */
	private static function close_( $parser, $part1 ) {
		// Infinite loop test
		if ( isset( $parser->mTemplatePath[$part1] ) ) {
			unset( $parser->mTemplatePath[$part1] );
		} else {
			wfDebug( __METHOD__ . ": close unopened template loop at '$part1'\n" );
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
	 */
	private static function parse_( $parser, $title, $text, $part1, $skiphead = 0 ) {
		// if someone tries something like<section begin=blah>lst only</section>
		// text, may as well do the right thing.
		$text = str_replace( '</section>', '', $text );

		if ( self::open_( $parser, $part1 ) ) {
			// Try to get edit sections correct by munging around the parser's guts.
			return [ $text, 'title' => $title, 'replaceHeadings' => true,
				'headingOffset' => $skiphead, 'noparse' => false, 'noargs' => false ];
		} else {
			return "[[" . $title->getPrefixedText() . "]]" .
				"<!-- WARNING: LST loop detected -->";
		}
	}

	/*
	 * And now, the labeled section transclusion
	 */

	/**
	 * Parser tag hook for <section>.
	 * The section markers aren't paired, so we only need to remove them.
	 *
	 * @param string $in
	 * @param array $assocArgs
	 * @param Parser|null $parser
	 * @return string HTML output
	 */
	public static function noop( $in, $assocArgs = [], $parser = null ) {
		return '';
	}

	/**
	 * Generate a regex to match the section(s) we're interested in.
	 * @param string $sec Name of target section
	 * @param string $to Optional name of section to end with, if transcluding
	 *                   multiple sections in sequence. If blank, will assume
	 *                   same section name as started with.
	 * @return string regex
	 */
	private static function getPattern_( $sec, $to ) {
		$beginAttr = self::getAttrPattern_( $sec, 'begin' );
		if ( $to == '' ) {
			$endAttr = self::getAttrPattern_( $sec, 'end' );
		} else {
			$endAttr = self::getAttrPattern_( $to, 'end' );
		}

		$sections = [ 'section' ];
		$localName = self::getLocalName( 'section' );
		if ( $localName !== null ) {
			$sections[] = $localName;
		}
		$section_re = '(?i:' . implode( '|', $sections ) . ')';

		return "/<$section_re$beginAttr\/?>(.*?)\n?<$section_re$endAttr\/?>/s";
	}

	/**
	 * Generate a regex fragment matching the attribute portion of a section tag
	 * @param string $sec Name of the target section
	 * @param string $type Either "begin" or "end" depending on the type of section tag to be matched
	 * @return string
	 */
	private static function getAttrPattern_( $sec, $type ) {
		$sec = preg_quote( $sec, '/' );
		$ws = "(?:\s+[^>]*)?"; // was like $ws="\s*"
		$attrs = [ $type ];
		$localName = self::getLocalName( $type );
		if ( $localName !== null ) {
			$attrs[] = $localName;
		}
		$attrName = '(?i:' . implode( '|', $attrs ) . ')';
		return "$ws\s+$attrName=(?:$sec|\"$sec\"|'$sec')$ws";
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
	 */
	private static function countHeadings_( $text, $limit ) {
		$pat = '^(={1,6}).+\1\s*$()';

		$count = 0;
		$offset = 0;
		$m = [];
		while ( preg_match( "/$pat/im", $text, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			if ( $m[2][1] > $limit ) {
				break;
			}

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
	 * @param Title &$title normalized title object
	 * @param string &$text wikitext output
	 * @return string bool true if returning text, false if target not found
	 */
	private static function getTemplateText_( $parser, $page, &$title, &$text ) {
		$title = Title::newFromText( $page );

		if ( is_null( $title ) ) {
			$text = '';
			return true;
		} else {
			if ( method_exists( $parser, 'fetchTemplateAndTitle' ) ) {
				list( $text, $title ) = $parser->fetchTemplateAndTitle( $title );
			} else {
				$text = $parser->fetchTemplate( $title );
			}
		}

		// if article doesn't exist, return a red link.
		if ( $text == false ) {
			$text = "[[" . $title->getPrefixedText() . "]]";
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Set up some variables for MW-1.12 parser functions
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @param string $func
	 * @return array|string
	 */
	private static function setupPfunc12( $parser, $frame, $args, $func = 'lst' ) {
		if ( !count( $args ) ) {
			return '';
		}

		$title = Title::newFromText( trim( $frame->expand( array_shift( $args ) ) ) );
		if ( !$title ) {
			return '';
		}
		if ( !$frame->loopCheck( $title ) ) {
			return '<span class="error">'
				. wfMessage( 'parser-template-loop-warning', $title->getPrefixedText() )
					->inContentLanguage()->text()
				. '</span>';
		}

		list( $root, $finalTitle ) = $parser->getTemplateDom( $title );

		// if article doesn't exist, return a red link.
		if ( $root === false ) {
			return "[[" . $title->getPrefixedText() . "]]";
		}

		$newFrame = $frame->newChild( false, $finalTitle );
		if ( !count( $args ) ) {
			return $newFrame->expand( $root );
		}

		$begin = trim( $frame->expand( array_shift( $args ) ) );

		$repl = null;
		if ( $func == 'lstx' ) {
			if ( !count( $args ) ) {
				$repl = '';
			} else {
				$repl = trim( $frame->expand( array_shift( $args ) ) );
			}
		}

		if ( !count( $args ) ) {
			$end = $begin;
		} else {
			$end = trim( $frame->expand( array_shift( $args ) ) );
		}

		$beginAttr = self::getAttrPattern_( $begin, 'begin' );
		$beginRegex = "/^$beginAttr$/s";
		$endAttr = self::getAttrPattern_( $end, 'end' );
		$endRegex = "/^$endAttr$/s";

		return compact( 'root', 'newFrame', 'repl', 'beginRegex', 'begin', 'endRegex' );
	}

	/**
	 * Returns true if the given extension name is "section"
	 * @param string $name
	 * @return bool
	 */
	private static function isSection( $name ) {
		$name = strtolower( $name );
		$sectionLocal = self::getLocalName( 'section' );
		return (
			$name === 'section'
			|| ( $sectionLocal !== null && $name === strtolower( $sectionLocal ) )
		);
	}

	/**
	 * Returns the text for the inside of a split <section> node
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $parts
	 * @return string
	 */
	private static function expandSectionNode( $parser, $frame, $parts ) {
		if ( isset( $parts['inner'] ) ) {
			return $parser->replaceVariables( $parts['inner'], $frame );
		} else {
			return '';
		}
	}

	/**
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @return array|string
	 */
	public static function pfuncIncludeObj( $parser, $frame, $args ) {
		$setup = self::setupPfunc12( $parser, $frame, $args, 'lst' );
		if ( !is_array( $setup ) ) {
			return $setup;
		}

		/**
		 * @var $root PPNode
		 */
		$root = $setup['root'];
		/**
		 * @var $newFrame PPFrame
		 */
		$newFrame = $setup['newFrame'];
		$beginRegex = $setup['beginRegex'];
		$endRegex = $setup['endRegex'];
		$begin = $setup['begin'];

		$text = '';
		$node = $root->getFirstChild();
		// @codingStandardsIgnoreStart
		while ( $node ) {
			// If the name of the begin node was specified, find it.
			// Otherwise transclude everything from the beginning of the page.
			if ( $begin != '' ) {
				// Find the begin node
				$found = false;
				for ( ; $node; $node = $node->getNextSibling() ) {
					if ( $node->getName() != 'ext' ) {
						continue;
					}
					$parts = $node->splitExt();
					$parts = array_map( [ $newFrame, 'expand' ], $parts );
					if ( self::isSection( $parts['name'] ) ) {
						if ( preg_match( $beginRegex, $parts['attr'] ) ) {
							$found = true;
							break;
						}
					}
				}
				if ( !$found || !$node ) {
					break;
				}
			}

			// Write the text out while looking for the end node
			$found = false;
			for ( ; $node; $node = $node->getNextSibling() ) {
				if ( $node->getName() === 'ext' ) {
					$parts = $node->splitExt();
					$parts = array_map( [ $newFrame, 'expand' ], $parts );
					if ( self::isSection( $parts['name'] ) ) {
						if ( preg_match( $endRegex, $parts['attr'] ) ) {
							$found = true;
							break;
						}
						$text .= self::expandSectionNode( $parser, $newFrame, $parts );
					} else {
						$text .= $newFrame->expand( $node );
					}
				} else {
					$text .= $newFrame->expand( $node );
				}
			}
			if ( !$found ) {
				break;
			} elseif ( $begin == '' ) {
				// When the end node was found and text is transcluded from
				// the beginning of the page, finish the transclusion
				break;
			}

			$node = $node->getNextSibling();
		}
		// @codingStandardsIgnoreEnd
		return $text;
	}

	/**
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @return array|string
	 */
	public static function pfuncExcludeObj( $parser, $frame, $args ) {
		$setup = self::setupPfunc12( $parser, $frame, $args, 'lstx' );
		if ( !is_array( $setup ) ) {
			return $setup;
		}

		/**
		 * @var $root PPNode
		 */
		$root = $setup['root'];
		/**
		 * @var $newFrame PPFrame
		 */
		$newFrame = $setup['newFrame'];
		$beginRegex = $setup['beginRegex'];
		$endRegex = $setup['endRegex'];
		$repl = $setup['repl'];

		$text = '';
		// @codingStandardsIgnoreStart
		for ( $node = $root->getFirstChild(); $node; $node = $node ? $node->getNextSibling() : false ) {
			// Search for the start tag
			$found = false;
			for ( ; $node; $node = $node->getNextSibling() ) {
				if ( $node->getName() == 'ext' ) {
					$parts = $node->splitExt();
					$parts = array_map( [ $newFrame, 'expand' ], $parts );
					if ( self::isSection( $parts['name'] ) ) {
						if ( preg_match( $beginRegex, $parts['attr'] ) ) {
							$found = true;
							break;
						}
						$text .= self::expandSectionNode( $parser, $newFrame, $parts );
					} else {
						$text .= $newFrame->expand( $node );
					}
				} else {
					$text .= $newFrame->expand( $node );
				}
			}

			if ( !$found ) {
				break;
			}

			// Append replacement text
			$text .= $repl;

			// Search for the end tag
			for ( ; $node; $node = $node->getNextSibling() ) {
				if ( $node->getName() == 'ext' ) {
					$parts = $node->splitExt( $node );
					$parts = array_map( [ $newFrame, 'expand' ], $parts );
					if ( self::isSection( $parts['name'] ) ) {
						if ( preg_match( $endRegex, $parts['attr'] ) ) {
							$text .= self::expandSectionNode( $parser, $newFrame, $parts );
							break;
						}
					}
				}
			}
		}
		// @codingStandardsIgnoreEnd
		return $text;
	}

	/**
	 * section inclusion - include all matching sections
	 *
	 * A parser extension that further extends labeled section transclusion,
	 * adding a function, #lsth for transcluding marked sections of text,
	 *
	 * @todo MW 1.12 version, as per #lst/#lstx
	 *
	 * @param Parser $parser
	 * @param string $page
	 * @param string $sec
	 * @param string $to
	 * @return mixed|string
	 */
	public static function pfuncIncludeHeading( $parser, $page = '', $sec = '', $to = '' ) {
		if ( self::getTemplateText_( $parser, $page, $title, $text ) == false ) {
			return $text;
		}

		// Generate a regex to match the === classical heading section(s) === we're
		// interested in.
		if ( $sec == '' ) {
			$begin_off = 0;
			$head_len = 6;
		} else {
			$pat = '^(={1,6})\s*' . preg_quote( $sec, '/' ) . '\s*\1\s*($)';
			if ( preg_match( "/$pat/im", $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$begin_off = $m[2][1];
				$head_len = strlen( $m[1][0] );
			} else {
				return '';
			}

		}

		if ( $to != '' ) {
			// if $to is supplied, try and match it. If we don't match, just
			// ignore it.
			$pat = '^(={1,6})\s*' . preg_quote( $to, '/' ) . '\s*\1\s*$';
			if ( preg_match( "/$pat/im", $text, $m, PREG_OFFSET_CAPTURE, $begin_off ) ) {
				$end_off = $m[0][1] - 1;
			}
		}

		if ( !isset( $end_off ) ) {
			$pat = '^(={1,' . $head_len . '})(?!=).*?\1\s*$';
			if ( preg_match( "/$pat/im", $text, $m, PREG_OFFSET_CAPTURE, $begin_off ) ) {
				$end_off = $m[0][1] - 1;
			}
		}

		$nhead = self::countHeadings_( $text, $begin_off );

		if ( isset( $end_off ) ) {
			$result = substr( $text, $begin_off, $end_off - $begin_off );
		} else {
			$result = substr( $text, $begin_off );
		}

		if ( method_exists( $parser, 'getPreprocessor' ) ) {
			$frame = $parser->getPreprocessor()->newFrame();
			$dom = $parser->preprocessToDom( $result, Parser::PTD_FOR_INCLUSION );
			$result = $frame->expand( $dom );
		}

		return self::parse_( $parser, $title, $result, "#lsth:${page}|${sec}", $nhead );
	}
}
