<?php
/** 
*
* WP-United Plugin Fixer
*
* @package Plugin-Fixer
* @version $Id: wp-united.php,v0.9.5[phpBB2]/v 0.7.1[phpBB3] 2009/05/18 John Wells (Jhong) Exp $
* @copyright (c) 2006-2009 wp-united.com
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
* @author John Wells
*
* 
*/

if ( !defined('IN_PHPBB') ) exit;
/**
* 	An abstraction layer for loading WordPress plugins. If Plugin fixes are enabled, this layer
* "compiles" plugins (e.g. parses them and modifies them as appropriate to make them compatible
* and then caches the compiled code for execution on successive runs of wordpress.
* 
*/
class WPU_WP_Plugins {
	
	var $pluginDir;
	var $wpuVer;
	var $wpVer;
	var $compat;
	var $strCompat;
	var $globals;
	var $mainEntry;
	
	var $fixCoreFiles;
		
	
	
	
	/**
	 * This class MUST be called as a singleton through this method
	 * @param string $wpPluginDir The WordPress plugin directory
	 * @param string $wpuVer WP-United version
	 * @param string $wpVer WordPress version
	 * @param string $compat True if WordPress is in global scope
	 */
	function getInstance($wpPluginDir, $wpuVer, $wpVer, $compat) {
		static $instance;
		if (!isset($instance)) {
			$instance = new WPU_WP_Plugins($wpPluginDir, $wpuVer, $wpVer, $compat);
        } 
        	return $instance;
    }		
    
	/**
	 * Class constructor
	 * @access private
	 */
	function WPU_WP_Plugins($wpPluginDir, $wpuVer, $wpVer, $compat) {
		$this->pluginDir =  add_trailing_slash(realpath($wpPluginDir));
		$this->compat = $compat;
		$this->wpuVer = $wpuVer;
		$this->wpVer = $wpVer;
		$this->strCompat = ($this->wpu_compat) ? "true" : "false";
		$this->mainEntry = false;
		$this->globals = (array)get_option('wpu_plugins_globals');
		$this->oldGlobals = $this->globals;
		
		// problematic WordPress files that could be require()d by a function
		$this->fixCoreFiles = array(
			add_trailing_slash(realpath(ABSPATH)) . 'wp-config.php',
			add_trailing_slash(realpath(ABSPATH . WPINC)) . 'registration.php'
		);
	}
	
	/**
	 * Returns compiled plugin file to execute
	 * @param string $plugin The full path to the plugin
	 */
	function fix($plugin, $mainEntry = false) {
		global $wpuCache;
		
		$this->mainEntry = $mainEntry;
		
		if (stripos($plugin, 'wpu-plugin') === false) {
			if(file_exists($plugin)) {
				$cached = $wpuCache->get_plugin($plugin, $this->wpuVer, $this->wpVer, $this->strCompat);
				if (!$cached) {
					if(!$cached = $this->process_file($plugin)) {
						$cached = $plugin;
					}
				}
				return $cached;
			}
		}
		return $plugin;
	}	
	
	/**
	 * Process the file
	 * @access private
	 */
	function process_file($pluginLoc) {
		global $phpEx, $wpuCache;
		
		// We only process files in the plugins directory, unless that file is a known problem
		$thisLoc = add_trailing_slash(dirname(realpath($pluginLoc)));
		if(strpos($thisLoc, $this->pluginDir) === false) {
			if(in_array(realpath($pluginLoc), $this->fixCoreFiles)) {
				return $wpuCache->save_plugin('', $pluginLoc, $this->wpuVer, $this->wpVer, $this->strCompat);
			}
			return $pluginLoc;
		}
			
		$pluginContent = @file_get_contents($pluginLoc);
		
		// prevent plugins from calling exit
		$pluginContent = preg_replace(array('/[;\s]exit;/', '/[;\s]exit\(/'), array('wpu_complete(); exit;', 'wpu_complete(); exit('), $pluginContent);
	
		// identify all global vars
		if (!$this->compat) {
			preg_match_all('/\n[\s]*global[\s]*([^\n^\r^;^:]*)(;|:|\r|\n)/', $pluginContent, $glVars);
		
			$globs = array();
			foreach($glVars[1] as $varSec) {
				$vars = explode(',', $varSec);
				foreach($vars as $var) {
					$globs[] = trim(str_replace('$', '',$var));
				}
			}
			if(sizeof($globs)) {
				if(is_array($this->globals)) {
					if(sizeof($this->globals)) {
						$globs = array_merge($this->globals, $globs);
					}
				}
				$globs = array_merge(array_unique($globs));
				$this->globals = $globs;
			}
		}
		
		// prevent including files which WP-United has already processed and included
		$pluginContent = preg_replace('/\n[\s]*((include|require)(_once)?[\s]*\([^\)]*registration\.php)/', "\n if(!function_exists('wp_insert_user')) $1", $pluginContent);
		$pluginContent = preg_replace('/\n[\s]*((include|require)(_once)?[\s]*\([^\(]*(\([\s]*__FILE__[\s]*\))?[^\)]*wp-config\.php)/', "\n if(!defined('ABSPATH')) $1", $pluginContent);
	
		//prevent buggering up of include paths
		$pluginContent = str_replace('__FILE__', "'" . $pluginLoc . "'", $pluginContent);
	
		// identify all includes and redirect to plugin fixer cache, if appropriate
		preg_match_all('/\n[\s]*((include|require)(_once)?[\s]*[\(]?([;\n]*\.(' . $phpEx . '|php)[^\);\n]*)(\n|;))/', $pluginContent, $includes);
		foreach($includes[4] as $key => $value) {	
			if(!empty($includes[4][$key])) {
				$finalChar = ($includes[6][$key] == ';') ? ';' : '';
				$pluginContent = str_replace($includes[1][$key], $includes[2][$key] . $includes[3][$key] . '($GLOBALS[\'wpuPluginFixer\']->fix(' . "{$value})){$finalChar}", $pluginContent);
			}
		}
	
		
	
		$startToken = (preg_match('/^[\s]*<\?php/', $pluginContent)) ? '?'.'>' : '';
		$endToken = (preg_match('/\?' . '>[\s]*$/', $pluginContent)) ? '<'.'?php ' : ''; 
	
		$pluginContent = $startToken. trim($pluginContent) . $endToken;
	
		return $wpuCache->save_plugin($pluginContent, $pluginLoc, $this->wpuVer, $this->wpVer, $this->strCompat);

	}
	
	function save_globals() {
		// remove any blanks, and remove anything that could wreck global references
		$this->globals = array_diff($this->globals, array_merge(array(''), $GLOBALS['wpUtdInt']->globalRefs));
		if($this->globals != $this->oldGlobals) {
			update_option('wpu_plugins_globals', $this->globals);
		}
	}
	
	function get_globalString() {
		if(!$this->compat) {
			if(sizeof($this->globals) && (is_array($this->globals))) {
				$this->save_globals();
				return 'global $' . implode(', $', $this->globals) . ';';
			}
		}
		return '';
	}
	
	
}