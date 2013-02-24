#!/usr/bin/env php
<?php

/**
 * This is a generic code for checking php files for entry points.
 * Most php apps have a few entry points and the other files are loaded
 * by the framework as needed. Thus it is important that directly calling
 * them doesn't allow to run unintended code.
 *
 * The rules taken for a file safety are the following:
 *  - Any code inside a class is safe.
 *  - Any code inside a function is safe.
 *  - Any code after a structure like if (!defined(...)) { ... die() } is safe.
 *  - Operating with variables in the global space is safe (language files, extensions setting up hooks...)
 *  - Call of functions non-whitelisted in the global space is unsafe.
 *  - includes or requires in the global space are unsafe if we cannot safely recurse (specially if they use variables in getting the path...).
 *
 */

ini_set( "memory_limit", -1 );

function debug( $msg ) {
	global $debug;
	if ( $debug ) {
		echo "$msg\n";
	}
}

function getLastSignificantToken( $tokens, $i, $howold = 1 ) {
	for ( $i--; $i >= 0; $i-- ) {
		if ( ( strpos( '(&', $tokens[$i][0] ) === false ) && !in_array( $tokens[$i][0], array( T_WHITESPACE, T_COMMENT ) ) ) {
			if ( !--$howold )
				return $tokens[$i];
		}
	}
	return array( null );
}

/**
 * Return the filename being included or false
 */
function getIncludeFilename( $currentFilename, $tokens, $i ) {
	# Parses the /[ (]*(dirname *\( *__FILE__ *\) *)?T_CONSTANT_ENCAPSED_STRING[) ]*;/ regex
	static $lastFilename = array( false, "" );
	$absolute = false;

	while ( ( $tokens[$i] == '(' ) || ( $tokens[$i][0] == T_WHITESPACE ) ) {
		$i++;
	}

	if ( ( $tokens[$i][0] == T_STRING ) && $tokens[$i][1] == 'dirname' ) {
		$absolute = dirname( $currentFilename );
		do { $i++; } while ( $tokens[$i][0] == T_WHITESPACE );
		if ( $tokens[$i] != '(' ) return false;
		do { $i++; } while ( $tokens[$i][0] == T_WHITESPACE );
		if ( $tokens[$i][0] == T_DIR ) {
			$absolute = dirname( dirname( $currentFilename ) );
		} elseif ( $tokens[$i][0] != T_FILE ) {
			return false;
		}
		do { $i++; } while ( $tokens[$i][0] == T_WHITESPACE );
		if ( $tokens[$i] != ')' ) return false;
		do { $i++; } while ( $tokens[$i][0] == T_WHITESPACE );
		if ( $tokens[$i] != '.' ) return false;
		do { $i++; } while ( $tokens[$i][0] == T_WHITESPACE );
	} elseif ( $tokens[$i][0] == T_DIR ) {
		do { $i++; } while ( $tokens[$i][0] == T_WHITESPACE );
		if ( $tokens[$i] != '.' ) return false;
		do { $i++; } while ( $tokens[$i][0] == T_WHITESPACE );
		$absolute = dirname( $currentFilename );
	}

	$filetoken = $tokens[$i];
	if ( ( $filetoken[0] == T_STRING ) && ( $filetoken[1] == 'DO_MAINTENANCE' || $filetoken[1] == 'RUN_MAINTENANCE_IF_MAIN'  ) ) {
		// Hack for MediaWiki maintenance
		if ( substr( $lastFilename[1], -16 ) == '/Maintenance.php' ) {
			$filetoken[1] = "'" . str_replace( 'Maintenance.php',  'doMaintenance.php', $lastFilename[1] ) . "'"; # It will be treated as clean for the wrong way, but the final result is right.
			$absolute = $lastFilename[0];
		} else {
			return false;
		}
	} else if ( $filetoken[0] != T_CONSTANT_ENCAPSED_STRING ) {
		return false;
	}

	do {
		$i++;
	} while ( ( $tokens[$i] == ')' ) || ( $tokens[$i][0] == T_WHITESPACE ) );

	if ( $tokens[$i] != ';' )
		return false;

	$filename = substr( $filetoken[1], 1, -1 );
	if ( strpos( $filename, '\\' ) !== false ) {
		if ( $filetoken[1][0] === "'" ) {
			$filename = strtr( $filename, array( "\\\\" => "\\", "\\'" => "'" ) );
		} else {
			return false;
		}
	}
	$lastFilename =  array( $absolute, $filename );

	if ( $absolute === false && ( $filename[0] == '/' || ( substr(PHP_OS, 0, 3) == 'WIN' && substr( $filename, 1, 3 ) == ':\\\\' ) ) ) {
		$absolute = "";
	}

	if ( $absolute === false ) {
		$resolvedFilename = stream_resolve_include_path( $filename );
		if ( $resolvedFilename !== false ) {
			return $resolvedFilename;
		}
		$absolute = dirname( $currentFilename );
	}

	return $absolute . '/' . $filename;
}

function isEntryPoint( $file ) {
	static $evaluatedFiles = array();
	$whitelistedFunctions = array( 'define', 'defined', 'dirname', 'function_exists', 'class_exists', 'php_sapi_name', 'version_compare', 'getcwd' );

	$rpath = realpath( $file );
	if ( isset( $evaluatedFiles[$rpath] ) && substr( $rpath, -16 ) !== "/Benchmarker.php" ) {
		return $evaluatedFiles[$rpath];
	}
	$evaluatedFiles[$rpath] = true;

	$braces = 0;
	$safeBraces = 0;
	$definedAutomaton = token_get_all( "<?php if(!defined('constant_name')){" ); # TODO: Rob Church does extensions the other way
	$cliSapiAutomatons = array();
	$cliSapiAutomatons[] = token_get_all( "<?php if(php_sapi_name()!='cli'){" );
	$cliSapiAutomatons[] = token_get_all( "<?php if(PHP_SAPI!='cli'){" );
	array_shift( $definedAutomaton ); array_walk($cliSapiAutomatons, function(&$array,$key) { array_shift($array); });
	$definedAutomatonState = 0; $cliSapiAutomatonsState = str_split( str_repeat( '0', count( $cliSapiAutomatons ) ) );
	$inDefinedConditional = false;
	$mustDieOnThisSection = false;
	$contents = file_get_contents( $file );
	if ( $contents === false ) {
		debug( "Couldn't open file $file" );
		return true; # Something went wrong
	}
	$tokens = token_get_all( $contents );

	for ( $i = 0; $i < count( $tokens ); $i++ ) {
		if ( !$braces ) {
			if ( $tokens[$i][0] != T_WHITESPACE ) {
				if ( ( $tokens[$i] == $definedAutomaton[$definedAutomatonState] ) ||
					 ( ( $tokens[$i][0] == $definedAutomaton[$definedAutomatonState][0] ) )
					 && ( ( $tokens[$i][1] == $definedAutomaton[$definedAutomatonState][1] )
					 || ( $tokens[$i][0] == T_CONSTANT_ENCAPSED_STRING ) ) )  {
					$definedAutomatonState++;
					if ( $definedAutomatonState >= count( $definedAutomaton ) ) {
						$inDefinedConditional = true;
						$definedAutomatonState = 0;
					}
				} else {
					$definedAutomatonState = 0;
				}

				for ($j = 0; $j < count( $cliSapiAutomatons ); $j++) {
					if ( ( $tokens[$i] == $cliSapiAutomatons[$j][$cliSapiAutomatonsState[$j]] ) ||
						 ( ( $tokens[$i][0] == $cliSapiAutomatons[$j][$cliSapiAutomatonsState[$j]][0] )
						 && ( $tokens[$i][1] == $cliSapiAutomatons[$j][$cliSapiAutomatonsState[$j]][1] ) ) )
					{
						$cliSapiAutomatonsState[$j]++;
						if ( $cliSapiAutomatonsState[$j] >= count( $cliSapiAutomatons[$j] ) ) {
							$inDefinedConditional = true;
							$cliSapiAutomatonsState[$j] = 0;
						}
					} else {
						$cliSapiAutomatonsState[$j] = 0;
					}
				}
			}
		}

		if ( $tokens[$i] == '{' ) {
			$braces++;
		} elseif ( $tokens[$i] == '}' ) {
			if ( $mustDieOnThisSection ) {
				debug( $mustDieOnThisSection );
				return true;
			}
			$braces--;
			if ( $braces < $safeBraces ) {
				$safeBraces = 0;
			}
		} elseif ( ( $tokens[$i][0] == T_CURLY_OPEN ) || ( $tokens[$i][0] == T_DOLLAR_OPEN_CURLY_BRACES ) ) {
			$braces++;
		}

		if ( $braces < $safeBraces || !$safeBraces ) {
			if ( $tokens[$i][0] == T_CLONE ) {
				debug( "$file clones a class in line {$tokens[$i][2]}" );
				return true;
			} elseif ( $tokens[$i][0] == T_EVAL ) {
				debug( "$file executes an eval() in line {$tokens[$i][2]}" );
				return true;
			} elseif ( in_array( $tokens[$i][0], array( T_ECHO, T_PRINT ) ) ) {
				if ( $inDefinedConditional ) {
					/* Allow the echo if this file dies inside this if*/
					if ( !$mustDieOnThisSection ) {
						$mustDieOnThisSection = "$file uses {$tokens[$i][1]} in line {$tokens[$i][2]}";
					}
				} else {
					debug( "$file uses {$tokens[$i][1]} in line {$tokens[$i][2]}" );
					return true;
				}
			} elseif ( $tokens[$i][0] == T_GOTO ) {
				# This bypass our check
				debug( "$file uses goto in line {$tokens[$i][2]}" );
				return true;
			} elseif ( $tokens[$i][0] == T_STRING ) {
				$lastToken = getLastSignificantToken( $tokens, $i );
				if ( in_array( $lastToken[0], array( T_CLASS, T_EXTENDS, T_FUNCTION, T_IMPLEMENTS, T_INTERFACE ) ) ) {
					$safeBraces = $braces + 1;
				}
			} elseif ( in_array( $tokens[$i][0], array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ) ) ) {
				$filename = getIncludeFilename( $rpath, $tokens, $i + 1 );

				if ( !$filename || $iep = isEntryPoint( $filename ) ) {
					debug( "$file {$tokens[$i][1]}s another file in line {$tokens[$i][2]}" );
					return true;
				}
				if ( $iep === null ) {
					// There won't be any web access to this file below the include
					debug( "$file {$tokens[$i][1]}s $filename in line {$tokens[$i][2]} and it ends the request" );
					$evaluatedFiles[$rpath] = null;
					return null;
				}
			} elseif ( $tokens[$i][0] == T_INLINE_HTML ) {
				if ( $inDefinedConditional ) {
					/* Allow the echo if this file dies inside this if*/
					if ( !$mustDieOnThisSection ) {
						$mustDieOnThisSection = "$file outputs html in line {$tokens[$i][2]}";
					}
				} else {
					debug( "$file outputs html in line {$tokens[$i][2]}" );
					return true;
				}
			} elseif ( $tokens[$i][0] == T_NEW ) {
				debug( "$file creates a new object in line {$tokens[$i][2]}" );
				return true;
			} elseif ( $tokens[$i][0] == T_OPEN_TAG_WITH_ECHO ) {
				debug( "$file echoes with $tokens[$i][1] in line {$tokens[$i][2]}" );
				return true;
			} elseif ( in_array( $tokens[$i][0], array( T_RETURN, T_EXIT ) ) ) {
				if ( !$braces || $inDefinedConditional ) {
					debug( "$file ends its processing with a {$tokens[$i][1]} in line {$tokens[$i][2]}" );
					$evaluatedFiles[$rpath] = null;
					return null;
				}
			} elseif ( $tokens[$i] == '(' ) {
				$lastToken = getLastSignificantToken( $tokens, $i );
				if ( $lastToken[0] == T_VARIABLE ) {
					debug( "$file calls a variable function in line $lastToken[2]" );
					return true;
				} elseif ( $lastToken[0] == T_STRING ) {
					$prev = getLastSignificantToken( $tokens, $i, 2 );
					if ( $prev[0] == T_FUNCTION ) {
						# Function definition
					} else {
						# Function call
						if ( !in_array( $lastToken[1], $whitelistedFunctions ) ) {
							debug( "$file calls function $lastToken[1]() in line $lastToken[2]" );
							return true;
						}
					}
				}
			}
		}

	}
	$evaluatedFiles[$rpath] = false;
	return false;
}

$verbose = false;
$debug = false;
$entries = 0;
$total = 0;

array_shift( $argv );
if ( ( $argv[0] == '--verbose' ) || ( $argv[0] == '-v' ) ) {
	$verbose = true;
	array_shift( $argv );
}

if ( ( $argv[0] == '--debug' ) || ( $argv[0] == '-d' ) ) {
	$debug = true;
	array_shift( $argv );
}

foreach ( $argv as $arg ) {
	if ( isEntryPoint( $arg ) ) {
		$entries++;
		echo "$arg is an entry point\n";
	} else if ( $verbose ) {
		echo "$arg is not an entry point\n";
	}
	$total++;
}
echo "$entries/$total\n";

