<?php

/** 
*
* WP-United Hooks
*
* @package WP-United
* @version $Id: v0.8.1RC2 2010/01/20 John Wells (Jhong) Exp $
* @copyright (c) 2006-2010 wp-united.com
* @license http://opensource.org/licenses/gpl-license.php GNU Public License  
* @author John Wells
*
*/

/**
 */
if ( !defined('IN_PHPBB') ) {
	exit;
}

define('WPU_HOOK_ACTIVE', TRUE);
require_once ($phpbb_root_path . 'wp-united/version.' . $phpEx);
require_once ($phpbb_root_path . 'wp-united/options.' . $phpEx);
require_once($phpbb_root_path . 'wp-united/functions-general.' . $phpEx);

require_once($phpbb_root_path . 'wp-united/mod-settings.' . $phpEx);
require_once($phpbb_root_path . 'wp-united/options.' . $phpEx);		
$wpSettings = (empty($wpSettings)) ? get_integration_settings() : $wpSettings; 

if(!defined('ADMIN_START') && (!defined('WPU_PHPBB_IS_EMBEDDED')) ) {  
	if (!((defined('WPU_DISABLE')) && WPU_DISABLE)) {  
		$phpbb_hook->register('phpbb_user_session_handler', 'wpu_init');
		$phpbb_hook->register(array('template', 'display'), 'wpu_execute', 'last');
		$phpbb_hook->register('exit_handler', 'wpu_continue');
	}
	
}

// Add lang strings if this isn't blog.php
if( !defined('WPU_BLOG_PAGE')  && !defined('WPU_PHPBB_IS_EMBEDDED') ) {
	$user->add_lang('mods/wp-united');
}	

/**
 * Initialise WP-United variables and template strings
 */
function wpu_init(&$hook) {
	global $wpSettings, $phpbb_root_path, $phpEx, $template, $user, $config;

	if  ($wpSettings['installLevel'] == 10) {
		//Do a reverse integration?
		if (($wpSettings['showHdrFtr'] == 'REV') && !defined('WPU_BLOG_PAGE')) {
			define('WPU_REVERSE_INTEGRATION', true);
			if ($config['gzip_compress']) {
				if (@extension_loaded('zlib') && !headers_sent()) {
					ob_start('ob_gzhandler');
				}
			}
			ob_start();
		}
	} 	
}

/**
 * Capture the outputted page, and prevent phpBB from exiting
 * @todo: use better check to ensure hook is called on template->display and just drop for everything else
 */
function wpu_execute(&$hook, $handle) {
	global $wpuRunning, $wpSettings, $template, $innerContent, $phpbb_root_path, $phpEx, $db, $cache;
	// We only want this action to fire once
	if ( (!$wpuRunning) &&  ($wpSettings['installLevel'] == 10) && (isset($template->filename[$handle])) ) {
		$wpuRunning = true;
		//$hook->remove_hook(array('template', 'display'));
		
		$template->assign_vars(array(
			'U_BLOG'	 =>	append_sid($wpSettings['blogsUri'], false, false, $GLOBALS['user']->session_id),
			'S_BLOG'	=>	TRUE,
		));  
		
		
		if (defined('WPU_REVERSE_INTEGRATION') ) {
			$template->display($handle);
			$innerContent = ob_get_contents();
			ob_end_clean(); 
			//insert phpBB into a wordpress page
			include ($phpbb_root_path . 'wp-united/integrator.' . $phpEx);
		} elseif (defined('PHPBB_EXIT_DISABLED')) {
			/**
			 * page_footer was called, but we don't want to close the DB connection & cache yet
			 */
			$template->display($handle);
			$GLOBALS['bckDB'] = $db;
			$GLOBALS['bckCache'] = $cache;
			$db = ''; $cache = '';
			
			return "";
		} // else display as normal
	}
}

/**
 * Prevent phpBB from exiting
 */
function wpu_continue(&$hook) {
	if (defined('PHPBB_EXIT_DISABLED') && !defined('WPU_FINISHED')) {
		return "";
	}
}

?>