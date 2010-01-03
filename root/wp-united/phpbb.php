<?php
/** 
*
* @package WP-United
* @version $Id: phpbb.php,v0.8.0 2009/06/23 John Wells (Jhong) Exp $
* @copyright (c) 2006-2009 wp-united.com
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
* phpBB status abstraction layer
* When in WordPress, we often want to switch between phpBB & WordPress functions
* By accessing through this class, it ensures that things are done cleanly.
* This will eventually replace much of the awkward variable swapping that wp-integration-class is
* doing.
*/

/**
 */
if ( !defined('ABSPATH') && !defined('IN_PHPBB') ) exit;

/**
 * phpBB abstraction class -- a neat way to access phpBB from WordPress
 * 
 */
class WPU_Phpbb {


	var $wpTablePrefix;
	var $wpUser;
	var $wpCache;
	var $phpbbTablePrefix;
	var $phpbbUser;
	var $phpbbCache;
	var $phpbbDbName;
	var $state;
	var $lang;
	var $was_out;
	var $seo = false;
	var $url = '';
	
	/**
	 * Class initialisation
	 */
	function WPU_Phpbb() {
		if(defined('IN_PHPBB')) {
			$this->state = 'phpbb';
			$this->lang = $GLOBALS['user']->lang;
			
			$this->_calculate_url();

		}
		$this->was_out = false;
		$this->seo = false;
		

		
		
		
		
		/**
		 * error constats for $this->err_msg
		 * todo use wp_die for most errors instead
		 */
		define('GENERAL_ERROR', 100);
		define('CRITICAL_ERROR' , -100);
	}	
	
	/**
	 * Loads the phpBB environment if it is not already
	 */
	function load($rootPath) {
		global $phpbb_root_path, $phpEx, $IN_WORDPRESS, $db, $table_prefix, $wp_table_prefix, $wpSettings;
		global $auth, $user, $cache, $cache_old, $user_old, $config, $template, $dbname, $SID, $_SID;
		
		$this->_backup_wp_conflicts();
		
		define('IN_PHPBB', TRUE);
		
		$phpbb_root_path = $rootPath;
		$phpEx = substr(strrchr(__FILE__, '.'), 1);
		
		$this->_make_phpbb_env();
		
		require_once($phpbb_root_path . 'common.' . $phpEx);
		
		$user->session_begin();
		$auth->acl($user->data);
		$user->setup('mods/wp-united');

		require_once($phpbb_root_path . 'wp-united/mod-settings.' . $phpEx);		
		$wpSettings = (empty($wpSettings)) ? get_integration_settings() : $wpSettings; 
		
		 //fix phpBB SEO mod
		 global $phpbb_seo;
		 if (!empty($phpbb_seo) ) {
			 if(file_exists($phpbb_root_path . 'phpbb_seo/phpbb_seo_class.'.$phpEx)) {
				require_once($phpbb_root_path . 'phpbb_seo/phpbb_seo_class.'.$phpEx);
				$phpbb_seo = new phpbb_seo();
				$this->seo = true;
			}
		 }
		 
		 $this->lang = $GLOBALS['user']->lang;
		 
		 $this->_calculate_url();
		
		$this->_backup_phpbb_state();
		$this->_switch_to_wp_db();
		$this->_restore_wp_conflicts();
		$this->_make_wp_env();
	}
	
	/**
	 * Enters the phpBB environment
	 */
	function enter() { 
		$this->lang = (sizeof($this->phpbbUser->lang)) ? $this->phpbbUser->lang : $this->lang;
		if($this->state != 'phpbb') {
			$this->_backup_wp_conflicts();
			$this->_restore_phpbb_state();
			$this->_make_phpbb_env();
			$this->_switch_to_phpbb_db();
		}
	}
	
	/**
	 * Returns to WordPress
	 */
	function leave() { 
		$this->lang = (sizeof($GLOBALS['user']->lang)) ? $GLOBALS['user']->lang : $this->lang;
		if($this->state == 'phpbb') {
			$this->_backup_phpbb_state();
			$this->_switch_to_wp_db();
			$this->_restore_wp_conflicts();
			$this->_make_wp_env();
		}
	}
	
	/**
	 * Passes content through the phpBB word censor
	 */
	function censor($content) {
		$this->_enter_if_out();
		$content = censor_text($content);
		$this->_leave_if_just_entered();
		return $content;
	}
	
	/**
	 * Returns if the current user is logged in
	 */
	function user_logged_in() {
		$this->_enter_if_out();
		$result = ( empty($GLOBALS['user']->data['is_registered']) ) ? FALSE : TRUE;
		$this->_leave_if_just_entered();
		return $result;
	}
	
	/**
	 * Returns the currently logged-in user's username
	 */
	function get_username() {
		$this->_enter_if_out();
		$result = $GLOBALS['user']->data['username'];
		$this->_leave_if_just_entered();
		return $result;
	}
	
	/**
	 * Returns something from $user->userdata
	 */
	function get_userdata($key = '') {
		$this->_enter_if_out();
		if ( !empty($key) ) {
			$result = $GLOBALS['user']->data[$key];
		} else {
			$result = $GLOBALS['user']->data;
		}
		$this->_leave_if_just_entered();
		return $result;		
	}
	
	/**
	 * Returns a statistic
	 */
	function stats($stat) {
		 return $GLOBALS['config'][$stat];
	}
	
	
		/**
	 * Returns rank info for currently logged in, or specified, user.
	 */
	function get_user_rank_info($userID = '') {
		global $db;
		$this->_enter_if_out();
		
		if (!$userID ) {
			if( $this->user_logged_in() ) {
				$usrData = $this->get_userdata();
			} 
		} else {
			$sql = 'SELECT user_rank, user_posts 
						FROM ' . USERS_TABLE .
						' WHERE user_wpuint_id = ' . $userID;
				if(!($result = $db->sql_query($sql))) {
					$this->err_msg(GENERAL_ERROR, 'Could not query phpbb database', '', __LINE__, __FILE__, $sql);
				}
				$usrData = $db->sql_fetchrow($result);
		}
		if( $usrData ) {
				global $phpbb_root_path, $phpEx;
				if (!function_exists('get_user_rank')) {
					require_once($phpbb_root_path . 'includes/functions_display.php');
				}
				$rank = array();
				$rank['text'] = $rank['image_tag'] = $rank['image']  = '';
				get_user_rank($usrData['user_rank'], $usrData['user_posts'], $rank['text'], $rank['image_tag'], $rank['image']);
				$this->_leave_if_just_entered();
				return $rank;
		}
		$this->leave();
	}
	
	
	/**
	 * Lifts latest phpBB topics from the DB. (this is the phpBB2 version) 
	 * $forum_list limits to a specific forum (comma delimited list). $limit sets the number of posts fetched. 
	 */
	function get_recent_topics($forum_list = '', $limit = 50) {
		global $db, $auth;
		
		$this->_enter_if_out();

		$forum_list = (empty($forum_list)) ? array() :  explode(',', $forum_list); //forums to explicitly check
		$forums_check = array_unique(array_keys($auth->acl_getf('f_read', true))); //forums authorised to read posts in
		if (sizeof($forum_list)) {
			$forums_check = array_intersect($forums_check, $forum_list);
		}
		if (!sizeof($forums_check)) {
			return FALSE;
		}
		$sql = 'SELECT t.topic_id, t.topic_time, t.topic_title, u.username, u.user_id,
				t.topic_replies, t.forum_id, t.topic_poster, t.topic_status, f.forum_name
			FROM ' . TOPICS_TABLE . ' AS t, ' . USERS_TABLE . ' AS u, ' . FORUMS_TABLE . ' AS f 
			WHERE ' . $db->sql_in_set('f.forum_id', $forums_check)  . ' 
				AND t.topic_poster = u.user_id 
					AND t.forum_id = f.forum_id 
						AND t.topic_status <> 2 
			ORDER BY t.topic_time DESC';
			
		if(!($result = $db->sql_query_limit($sql, $limit, 0))) {
			$this->err_msg(GENERAL_ERROR, 'Could not query phpbb database', '', __LINE__, __FILE__, $sql);
		}		

		$posts = array();
		$i = 0;
		while ($row = $db->sql_fetchrow($result)) {
			$posts[$i] = array(
				'topic_id' 		=> $row['topic_id'],
				'topic_replies' => $row['topic_replies'],
				'topic_title' 	=> wpu_censor($row['topic_title']),
				'user_id' 		=> $row['user_id'],
				'username' 		=> $row['username'],
				'forum_id' 		=> $row['forum_id'],
				'forum_name' 	=> $row['forum_name']
			);
			$i++;
		}
		$db->sql_freeresult($result);
		$this->_leave_if_just_entered();
		return $posts;
	}		
	
	/**
	 * Displays a dying general error message
	 * @todo clean up all error messages to use wp_die() if possible
	 * Left here for compatibility while old errors still remain pending cleanup
	 * @deprecated
	 */
	function err_msg($errType, $msg = '', $title = '', $line = '', $file = '', $sql = '') {
		global $images, $wpUtdInt, $phpbb_root_path;
		//Exit the WordPress environment
		if ( isset($wpUtdInt) ) {
			if ( $wpUtdInt->wpLoaded ) {
				$this->enter();
				$wpUtdInt->exit_wp_integration();
			}
		}
		if ( $errType != CRITICAL_ERROR ) {
			$msg = '<img src="' . $phpbb_root_path . 'wp-united/images/wp-united-logo.gif" style="float: left;" /><br />' . $msg;
		}
		trigger_error($msg);
	}	
	
	/**
	 * Calculates the URL to the forum
	 * @access private
	 */
	function _calculate_url() {
			global $config;
			$server = $config['server_protocol'] . add_trailing_slash($config['server_name']);
			$scriptPath = add_trailing_slash($config['script_path']);
			$scriptPath= ( $scriptPath[0] == "/" ) ? substr($scriptPath, 1) : $scriptPath;
			$this->url = $server . $scriptPath;	
	}
	
	
	/**
	 * @access private
	 */
	function _make_phpbb_env() {
		global $IN_WORDPRESS;
		
		// WordPress removes $_COOKIE from $_REQUEST, which is the source of much wailing and gnashing of teeth
		$IN_WORDPRESS = 1; 
		$this->state = 'phpbb';
		$_REQUEST = array_merge($_COOKIE, $_REQUEST);
	}

	/**
	 * @access private
	 */	
	function _make_wp_env() {
		$this->state = 'wp';
		$_REQUEST = array_merge($_GET, $_POST);
	}

	/**
	 * @access private
	 */	
	function _backup_wp_conflicts() {
		global $table_prefix, $user, $cache;
		
		$this->wpTablePrefix = $table_prefix;
		$this->wpUser = (isset($user)) ? $user: '';
		$this->wpCache = (isset($cache)) ? $cache : '';
	}

	/**
	 * @access private
	 */	
	function _backup_phpbb_state() {
		global $table_prefix, $user, $cache, $dbname;

		$this->phpbbTablePrefix = $table_prefix;
		$this->phpbbUser = (isset($user)) ? $user: '';
		$this->phpbbCache = (isset($cache)) ? $cache : '';
		$this->phpbbDbName = $dbname;
	}

	/**
	 * @access private
	 */	
	function _restore_wp_conflicts() {
		global $table_prefix, $user, $cache;
		
		$user = $this->wpUser;
		$cache = $this->wpCache;
		$table_prefix = $this->wpTablePrefix;
	}

	/**
	 * @access private
	 */	
	function _restore_phpbb_state() {
		global $table_prefix, $user, $cache;
		
		$table_prefix = $this->phpbbTablePrefix;
		$user = $this->phpbbUser;
		$cache = $this->phpbbCache;
	}

	/**
	 * @access private
	 */	
	function _switch_to_wp_db() {
		if (!$this->phpbbDbName != DB_NAME) {
			mysql_select_db(DB_NAME);
		}		
	}
	
	/**
	 * @access private
	 */	
	function _switch_to_phpbb_db() {
		if (!$this->phpbbDbName != DB_NAME) {
			mysql_select_db($this->phpbbDbName);
		}			
	}
	
	/**
	 * Enters phpBB if we were out
	 * This is the same as the normal enter() function, but it records that we didn't have to enter
	 * Subsequent calls to _leave_if_just_entered ensure we don't leave.
	 * @access private
	 */
	function _enter_if_out() {
		$this->was_out = ($this->state != 'phpbb');
		if($this->was_out) {
			$this->enter();
		}
	}
	/**
	 * Leaves phpBB only if _enter_if_out actually did something
	 * MUST be preceded by a _leave_if_just_entered in the same call, or will be meaningless
	 * @access private
	 */
	function _leave_if_just_entered() {
		if($this->was_out) {
			$this->leave();
		}
		$this->was_out = false;	
	}	



}