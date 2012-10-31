<?php

/*
Plugin Name: WP-United
Plugin URI: http://www.wp-united.com
Description: WP-United connects to your phpBB forum and integrates user sign-on, behaviour and theming. Once your forum is up and running, you should not disable this plugin.
Author: John Wells
Author URI: http://www.wp-united.com
Version: v0.9.0 RC3 
Last Updated: 23 October 2012
* 
*/
 
/** 
*
* @package WP-United Connection Plugin
* @version $Id: wp-united.php,v0.8.0 2010/01/29 John Wells (Jhong) Exp $
* @copyright (c) 2006-2009 wp-united.com
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
* @author John Wells
*/

// this file will also be called in wp admin panel, when phpBB is not loaded. So we don't check IN_PHPBB.
// ABSPATH should *always* be set though!
if ( !defined('ABSPATH') ) {
	exit;
}



add_action('comment_form', 'wpu_comment_redir_field');
add_action('wp_head', 'wpu_inline_js');
add_action('edit_post', 'wpu_justediting');
add_action('publish_post', 'wpu_newpost', 10, 2);
add_action('wp_insert_post', 'wpu_capture_future_post', 10, 2); 
add_action('future_to_publish', 'wpu_future_to_published', 10); 
add_action('admin_footer', 'wpu_put_powered_text');
add_action('wp_head', 'wpu_done_head');
add_action('upload_files_browse', 'wpu_browse_attachments');
add_action('upload_files_browse-all', 'wpu_browse_attachments');
add_action('switch_theme', 'wpu_clear_header_cache');
add_action('loop_start', 'wpu_loop_entry'); 
add_action('admin_menu', 'wpu_add_meta_box'); 
add_action('register_post', 'wpu_check_new_user', 10, 3);
add_action('pre_comment_on_post', 'wpu_comment_redirector');
add_action('comments_open', 'wpu_comments_open', 10, 2);


add_filter('pre_user_login', 'wpu_fix_blank_username');
add_filter('validate_username', 'wpu_validate_username_conflict');
add_filter('get_comment_author_link', 'wpu_get_comment_author_link');
add_filter('comment_text', 'wpu_censor');
add_filter('comment_text', 'wpu_smilies');
add_filter('get_avatar', 'wpu_get_phpbb_avatar', 10, 5);
add_filter('template', 'wpu_get_template');
add_filter('stylesheet', 'wpu_get_stylesheet');
add_filter('option_blogname', 'wpu_blogname');
add_filter('option_blogdescription', 'wpu_blogdesc');
add_filter('option_home', 'wpu_homelink');
add_filter('the_content', 'wpu_content_parse_check' );
add_filter('the_title', 'wpu_censor');
add_filter('the_excerpt', 'wpu_censor');
add_filter('get_previous_post_where', 'wpu_prev_next_post');
add_filter('get_next_post_where', 'wpu_prev_next_post');
add_filter('upload_dir', 'wpu_user_upload_dir');
add_filter('feed_link', 'wpu_feed_link');
add_filter('comments_array', 'wpu_load_phpbb_comments', 10, 2);
add_filter('get_comments_number', 'wpu_comments_count', 10, 2);
add_filter('page_link', 'wpu_modify_pagelink', 10, 2);
add_filter('pre_option_comment_registration', 'wpu_no_guest_comment_posting');
add_filter('edit_comment_link', 'wpu_edit_comment_link', 10, 2);
add_filter('get_comment_link', 'wpu_comment_link', 10, 3);



if( !class_exists( 'WP_United' ) ):

class WP_United {

	private
		
		$enabled = false,
		$lastRun = false,
		$pluginLoaded = false,



		$actions = array(
			'init'			=>		'init_plugin',
			
			'wp_logout'		=>		'phpbb_logout',
	

			'comment_form'	=> 		'generate_smilies',
		
		
		),

		$filters = array(),

		$options = array();
	
	public
		$pluginPath = '',
		$wpPath = '',
		$wpHomeUrl = '',
		$wpBaseUrl = '',
		$pluginUrl = '';

	/**
	* Initialise the WP-United class
	*/
	public function __construct() {
		
		foreach( $this->actions as $action => $classMember) {
			add_action( $action, array( $this, $classMember ) );
		}

		foreach( $this->filters as $filter ) {
		add_filter( $filter, array( $this, $filter ) );
		}
	}
	
	
	
	public function init_plugin() {
		global $phpbb_root_path, $phpEx, $phpbbForum, $wpSettings;
		
		
		if($this->pluginLoaded) {
				return false;
		}
		$this->pluginLoaded = true;
		
		$this->wpPath = ABSPATH;
		$this->pluginPath = plugin_dir_path(__FILE__);
		$this->pluginUrl = plugins_url('wp-united') . '/';
		$this->wpHomeUrl = home_url('/'); // TODO: REPLACE $wpUri elsewhere
		$this->wpBaseUrl = site_url('/'); // TODO: REPLACE $wpUri in some instances
		$this->pluginUrl = plugins_url('wp-united/');
		

		
		
		require_once($this->pluginPath . 'functions-general.php');
		require_once($this->pluginPath . 'template-tags.php');
		require_once($this->pluginPath . 'login-integrator.php'); 
		require_once($this->pluginPath . 'functions-cross-posting.php');
	
		$wpSettings = get_option('wpu-settings');
		
		// this has to go prior to phpBB load so that connection can be disabled in the event of an error on activation.
		$this->process_adminpanel_actions();

		require_once($this->pluginPath .  'phpbb.php');
		$phpbbForum = new WPU_Phpbb();	
		


		
		if(!$this->is_enabled()) {
			$this->set_last_run('disconnected');
			
			$wpSettings['integrateLogin'] = 0;
			$wpSettings['showHdrFtr'] = 'NONE';
			return;
		} else {
			$this->get_last_run();
		}		
		
		// disable login integration if we couldn't override pluggables
		if(defined('WPU_CANNOT_OVERRIDE')) {
			$wpSettings['integrateLogin'] = 0;
		}		

		if(!isset($wpSettings['phpbb_path']) || !file_exists($wpSettings['phpbb_path'])) {
			$this->set_last_run('disconnected');
			return;
		}
		
		if($this->get_last_run() == 'connected') {
			return;
		}
		
		$this->set_last_run('connected');
		
		if($this->is_enabled()) {

			if ( !defined('IN_PHPBB') ) {
				$phpbb_root_path = $wpSettings['phpbb_path'];
				$phpEx = substr(strrchr(__FILE__, '.'), 1);
			}
			
						
			if ( !defined('IN_PHPBB') ) {
				if(is_admin()) {
					define('WPU_PHPBB_IS_EMBEDDED', TRUE);
				} else {
					define('WPU_BLOG_PAGE', 1);
				}
				$phpbbForum->load($phpbb_root_path);
			}
			
			$this->set_last_run('working');
			
			require_once($this->pluginPath . 'widgets.php');
			require_once($this->pluginPath . 'widgets2.php');
			
			//add_action('widgets_init', 'wpu_widgets_init_old');
			add_action('widgets_init', 'wpu_widgets_init');

			 /*if ( (stripos($_SERVER['REQUEST_URI'], 'wp-login') !== false) && (!empty($wpSettings['integrateLogin'])) ) {
				global $user_ID;
				get_currentuserinfo();
				if( ($phpbbForum->user_logged_in()) && ($id = get_wpu_user_id($user_ID)) ) {
					wp_redirect(admin_url());
				} else if ( (defined('WPU_MUST_LOGIN')) && WPU_MUST_LOGIN ) {
					$login_link = append_sid('ucp.'.$phpEx.'?mode=login&redirect=' . urlencode(esc_attr(admin_url())), false, false, $GLOBALS['user']->session_id);		
					wp_redirect($phpbbForum->url . $login_link);
				}
			} */
		}
		
		// This variable is used in phpBB template integrator || TODO: KILL!!!
		global $siteUrl;
		$siteUrl = get_option('siteurl');
		
		// enqueue any JS we need
		if ( !empty($wpSettings['phpbbSmilies'] ) && !is_admin() ) {
			wp_enqueue_script('wp-united', $this->pluginUrl . 'js/wpu-min.js', array(), false, true);
		}
		
		// fix broken admin bar on integrated page
		if(($wpSettings['showHdrFtr'] == 'FWD') && !empty($wpSettings['cssMagic'])) {
			wp_enqueue_script('wpu-fix-adminbar', $this->pluginUrl . 'js/wpu-fix-bar.js', array('admin-bar'), false, true);
		}
		
		if( !empty($wpSettings['integrateLogin']) && !defined('WPU_DISABLE_LOGIN_INT') ) {
				wpu_integrate_login();
		}
		
		return true; 
			

	}

	public function is_enabled() {
		$this->enabled = get_option('wpu-enabled');
		return $this->enabled;
	}
	public function enable() {
		$this->enabled = true;
		update_option('wpu-enabled', true);
	}
	public function disable() {
		$this->enabled = false;
		update_option('wpu-enabled', $this->enabled);
	}

	
	public function is_loaded() {
		return $this->pluginLoaded;
	}
	
	public function set_last_run($status) {
		if($this->lastRun != $status) {
			// transitions cannot go from 'working' to 'connected'.
			if( ($this->lastRun == 'working') && ($status == 'connected') ) {
				return;
			}
			$this->lastRun = $status;
			update_option('wpu-last-run', $status);
		}
	}
	
	public function get_last_run() {
	
		if(empty($this->lastRun)) {
			$this->lastRun = get_option('wpu-last-run');
		}
		
		 return $this->lastRun;
	}
	
	public function is_phpbb_loaded() {
		if($this->is_enabled() && ($this->get_last_run() == 'working')) {
			return true;
		}
		return false;
	}
	
	public function phpbb_logout() {
		if($this->is_phpbb_loaded()) {
			global $phpbbForum;
			$phpbbForum->logout();
		}
	}
	
	/**
	 * Function 'wpu_print_smilies' prints phpBB smilies into comment form
	 * @since WP-United 0.7.0
	*/
	public function generate_smilies() { 
		global $phpbbForum, $wpSettings;
		if ( !empty($wpSettings['phpbbSmilies'] ) ) {
			echo $phpbbForum->get_smilies();
		}
	}

	
	/**
	 * Process inbound actions and set up the settings panels after login integration has already taken place
	 */
	private function process_adminpanel_actions() {
		global $wpSettings;
		
		if(is_admin()) {
			
			// styles we need across admin
			wp_register_style('wpuAdminStyles', $this->pluginUrl . 'theme/admin-general.css');
			wp_enqueue_style('wpuAdminStyles'); 
		
			
			require_once($this->pluginPath . 'settings-panel.php');
			
			// the settings page has detected an error and asked to abort
			if( isset($_POST['wpudisable']) && check_ajax_referer( 'wp-united-disable') ) {
				wpu_disable_connection('server-error'); 
			}	

			// the user wants to manually disable
			if( isset($_POST['wpudisableman']) && check_ajax_referer( 'wp-united-disable') ) {
				wpu_disable_connection('manual');
			}		

			if( isset($_POST['wpusettings-transmit']) && check_ajax_referer( 'wp-united-transmit') ) {
				wpu_process_settings();
				$wpSettings = get_option('wpu-settings');
			}
			
			// file tree
			if( isset($_POST['filetree']) && check_ajax_referer( 'wp-united-filetree') ) {
				wpu_filetree();
			}	
			
		}
	}
	

	
}

global $wpUnited;

$wpUnited = new WP_United();


endif;








/**
 * Disable WPU and putput result directly to the calling script
 *
 */

function wpu_disable_connection($type) {
	global $wpUnited;
	
	if(!$wpUnited->is_enabled()) {
		die(__('WP-United is already disabled'));
	}
	
	$wpUnited->disable();
	
	if($type == 'error') {
				
		if($wpUnited->get_last_run() == 'disconnected') {
			die('[ERROR]' . __('WP-United could not find phpBB at the selected path. WP-United is not connected.'));
		} elseif($wpUnited->get_last_run() == 'connected') {
			die('[ERROR]' . __('WP-United could not successfully run phpBB at the selected path. WP-United is halted.'));
		} else {
			die('[ERROR]' . __('WP-United could not successfully run phpBB without errors. WP-United has been disconnected.'));
		}
	} elseif($type=='server-error') {
		die('OK');
	} elseif($type=='manual') {
		die(__('WP-United Disabled Successfully'));
	}
	
 	_e('WP-United Disabled');
	return;

}


/**
 * The default shutdown action, wp_ob_end_flush_all, causes PHP notices with zlib compression. 
 * So we turn it off and replace it with the diff suggested at
 * http://rustyroy.blogspot.jp/2010/12/various-stuff_20.html
 * See also http://core.trac.wordpress.org/attachment/ticket/18525/18525.6.diff 
 */
remove_action('shutdown', 'wp_ob_end_flush_all', 1);
add_action('shutdown', 'wpu_ob_end_flush_all', 1);
function wpu_ob_end_flush_all() {
	$levels = ob_get_level();
	for ($i=0; $i<$levels; $i++){
		$obStatus = ob_get_status();
		if (!empty($obStatus['type']) && $obStatus['status']) {
			ob_end_flush();
		}
	}

}








/**
 * Check the permalink to see if this is a link to the forum. 
 * If it is, replace it with the real forum link
 */
function wpu_modify_pagelink($permalink, $post) {
	global $wpSettings, $phpbbForum, $phpEx;
	
	if ( !empty($wpSettings['useForumPage']) ) {
		$forumPage = get_option('wpu_set_forum');
		if(!empty($forumPage) && ($forumPage == $post)) {
			// If the forum and blog are both in root, add index.php to the end
			$forumPage = ($phpbbForum->url == get_option('siteurl')) ? $phpbbForum->url . 'index.' . $phpEx : $phpbbForum->url;
			return $forumPage; 
		}
	}
	
	return $permalink;
}




/**
 * Adds the WP-United copyright statement in all dashboards
 * Please DO NOT remove this!
 */
function wpu_put_powered_text() {
	global $wp_version, $wpSettings, $phpbbForum;
	echo '<p  id="poweredby">' . sprintf($phpbbForum->lang['wpu_dash_copy'], '<a href="http://www.wp-united.com">', '</a>') . '</p>';
}





/**
 * Shows the "Your blog settings" menu
 * 
 */
function wpu_menuSettings() { 
	global $wpSettings, $user_ID, $wp_roles, $phpbbForum, $phpEx;
	$profileuser = get_user_to_edit($user_ID);
	$bookmarklet_height= 440;
	$page_output = '';

	if ( isset($_GET['updated']) ):  ?>
		<div id="message" class="updated fade">
		<p><strong>  <?php _e('Settings updated.'); ?> </strong></p>
		</div>
	<?php endif; ?>
	
	<div class="wrap" id="profile-page">
	<?php screen_icon('profile'); ?>
	<h2> <?php echo $phpbbForum->lang['wpu_blog_details']?> </h2>
	<form name="profile" id="your-profile" action="admin.php?noheader=true&amp;page=wp-united&amp;wpu_action=update-blog-profile" method="post">
	<?php wp_nonce_field('update-blog-profile_' . $user_ID); 	?>
	<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr($_SERVER['REQUEST_URI']); ?>" />
	<?php if ( $ref = wp_get_original_referer() ): ?>
		<input type="hidden" name="_wp_original_http_referer" value="<?php echo esc_attr(stripslashes($ref)); ?>" />
	<?php endif; ?>
		<input type="hidden" name="checkuser_id" value="<?php echo $user_ID; ?>" />
	</p>	

	
	<h3><?php echo $phpbbForum->lang['wpu_blog_about']; ?></h3>
		<input type="hidden" name="email" value="<?php echo $profileuser->user_email; ?>" />
		<?php /* Retrieve blog options */
		$blog_title = get_user_meta($user_ID, 'blog_title', true);
		$blog_tagline = get_user_meta($user_ID, 'blog_tagline', true); ?>
		<table class="form-table">
			<tr>
				<th><label><?php echo $phpbbForum->lang['wpu_blog_about_title']; ?></label></th>
				<td><input type="text" name="blog_title" value="<?php echo $blog_title; ?>" /></td>
			</tr>
			<tr>
				<th><label><?php echo $phpbbForum->lang['wpu_blog_about_tagline']; ?></label></th>
				<td><input type="text" name="blog_tagline" value="<?php echo $blog_tagline ; ?>" /> <span class="description"><?php _e('In a few words, explain what this blog is about.'); ?></span></td>
			</tr>			
		</table>

	<p class="submit">
		<input type="submit" class="button-primary" value="<?php  echo $phpbbForum->lang['wpu_update_blog_details'] ?>" name="submit" />
	</p>
	</form>
		
	</div>
	<?php

 
}

/**
 * If Style switching is allowed, displays the author theme switching menu
 *	Modelled on WP's existing themes.php
 */
function wp_united_display_theme_menu() {

	global $user_ID, $title, $parent_file, $wp_version, $phpEx, $phpbbForum;
	
	if ( ! validate_current_theme() ) { ?>
	<div id="message1" class="updated fade"><p><?php echo $phpbbForum->lang['wpu_theme_broken']; ?></p></div>
	<?php } elseif ( isset($_GET['activated']) ) { ?>
	<div id="message2" class="updated fade"><p><?php echo sprintf($phpbbForum->lang['wpu_theme_activated'], '<a href="' . wpu_homelink('wpu-activate-theme') . '/">', '</a>'); ?></p></div>
	<?php }
	
	$themes = get_themes();

	$theme_names = array_keys($themes);
	$user_theme = 'WordPress Default';

	$user_template = get_user_meta($user_ID, 'WPU_MyTemplate', true); 
	$user_stylesheet = get_user_meta($user_ID, 'WPU_MyStylesheet', true);

	$site_theme = current_theme_info();

	$user_theme = $site_theme->title; // if user hasn't set a theme yet, it is the same as site default

	
	// get current user theme
	if ( $themes ) {
		foreach ($theme_names as $theme_name) {
			if ( $themes[$theme_name]['Stylesheet'] == $user_stylesheet &&
					$themes[$theme_name]['Template'] == $user_template ) {
				$user_theme = $themes[$theme_name]['Name'];
				break;
			}
		}
	}
	
	$template = $themes[$user_theme]['Template'];
	$stylesheet = $themes[$user_theme]['Stylesheet'];
	$title = $themes[$user_theme]['Title'];
	$version = $themes[$user_theme]['Version'];
	$description = $themes[$user_theme]['Description'];
	$author = $themes[$user_theme]['Author'];
	$screenshot = $themes[$user_theme]['Screenshot'];
	$stylesheet_dir = $themes[$user_theme]['Stylesheet Dir'];
	$theme_root = $themes[$theme_name]['Theme Root'];
	$theme_root_uri = $themes[$theme_name]['Theme Root URI'];	
	$tags = $themes[$user_theme]['Tags'];	

	if ($wp_version > 2.50) {
	
		// paginate if necessary
		ksort( $themes );
		$theme_total = count( $themes );
		$per_page = 15;

		if ( isset( $_GET['pagenum'] ) )
			$page = absint( $_GET['pagenum'] );

		if ( empty($page) )
			$page = 1;

		$start = $offset = ( $page - 1 ) * $per_page;

		$page_links = paginate_links( array(
			'base' => add_query_arg( 'pagenum', '%#%' ) . '#themenav',
			'format' => '',
			'prev_text' => __('&laquo;'),
			'next_text' => __('&raquo;'),
			'total' => ceil($theme_total / $per_page),
			'current' => $page
		));

		$themes = array_slice( $themes, $start, $per_page );
	
		$pageTitle = $phpbbForum->lang['wpu_blog_your_theme']; ?>
		
		<div class="wrap">
			<?php screen_icon('themes'); ?>
			<h2><?php echo wp_specialchars( $pageTitle ); ?></h2>
		<?php /* CURRENT THEME */ ?>
			<h3><?php _e('Current Theme'); ?></h3>
			<div id="current-theme">
				<?php if ( $screenshot ) : ?>

				<img src="<?php echo $theme_root_uri  . '/' . $stylesheet . '/' . $screenshot; ?>" alt="<?php _e('Current theme preview'); ?>" />
				<?php endif; ?>
				<h4><?php printf(_c('%1$s %2$s by %3$s|1: theme title, 2: theme version, 3: theme author'), $title, $version, $author) ; ?></h4>
				<p class="description"><?php echo $description; ?></p>
				<?php if ( $tags ) : ?>
					<p><?php _e('Tags:'); ?> <?php echo join(', ', $tags); ?></p>
				<?php endif; ?>
			</div>
			
			<div class="clear"></div>
			<h3><?php _e('Available Themes'); ?></h3>
			<div class="clear"></div>
			
			<?php /* PAGINATION */ ?>
			<?php if ( $page_links ) : ?>
			<div class="tablenav">
			<div class="tablenav-pages"><?php $page_links_text = sprintf( '<span class="displaying-num">' . __( 'Displaying %s&#8211;%s of %s' ) . '</span>%s',
				number_format_i18n( $start + 1 ),
				number_format_i18n( min( $page * $per_page, $theme_total ) ),
				number_format_i18n( $theme_total ),
				$page_links
			); echo $page_links_text; ?></div>
			</div>
			<?php endif; ?>
		
		
			<?php /* OTHER THEMES */ ?>
			
			<?php if ( 1 < $theme_total ) { ?>
			<table id="availablethemes" cellspacing="0" cellpadding="0">
			<?php
			$style = '';

			$theme_names = array_keys($themes);
			natcasesort($theme_names);

			$rows = ceil(count($theme_names) / 3);
			for ( $row = 1; $row <= $rows; $row++ )
				for ( $col = 1; $col <= 3; $col++ )
					$table[$row][$col] = array_shift($theme_names);

			foreach ( $table as $row => $cols ) {
			?>
			<tr>
			<?php
			foreach ( $cols as $col => $theme_name ) {
				if ($theme_name != $user_theme) {
					$class = array('available-theme');
					if ( $row == 1 ) $class[] = 'top';
					if ( $col == 1 ) $class[] = 'left';
					if ( $row == $rows ) $class[] = 'bottom';
					if ( $col == 3 ) $class[] = 'right';?>
					<td class="<?php echo join(' ', $class); ?>">
					<?php if ( !empty($theme_name) ) {
						$template = $themes[$theme_name]['Template'];
						$stylesheet = $themes[$theme_name]['Stylesheet'];
						$title = $themes[$theme_name]['Title'];
						$version = $themes[$theme_name]['Version'];
						$description = $themes[$theme_name]['Description'];
						$author = $themes[$theme_name]['Author'];
						$screenshot = $themes[$theme_name]['Screenshot'];
						$stylesheet_dir = $themes[$theme_name]['Stylesheet Dir'];
						$theme_root = $themes[$theme_name]['Theme Root'];
						$theme_root_uri = $themes[$theme_name]['Theme Root URI'];							
						$preview_link = clean_url( get_option('home') . '/');
						$preview_link = htmlspecialchars( add_query_arg( array('preview' => 1, 'template' => $template, 'stylesheet' => $stylesheet, 'TB_iframe' => 'true', 'width' => 600, 'height' => 400 ), $preview_link ) );
						$preview_text = esc_attr( sprintf( __('Preview of "%s"'), $title ) );
						$tags = $themes[$theme_name]['Tags'];
						$thickbox_class = 'thickbox';
						$activate_link = wp_nonce_url('admin.php?page=wp-united-theme-menu&amp;noheader=true&amp;wpu_action=activate&amp;template=' . $template . '&amp;stylesheet=' . $stylesheet, 'wp-united-switch-theme_' . $template);
						$activate_text = esc_attr( sprintf( __('Activate "%s"'), $title ) );
						?>
						<?php if ( $screenshot ) { ?>
							<a href="<?php echo $preview_link; ?>" title="<?php echo $preview_text; ?>" class="<?php echo $thickbox_class; ?> screenshot">
								<img src="<?php echo $theme_root_uri  . '/' . $stylesheet . '/' . $screenshot; ?>" alt="" />
							</a>
						<?php } ?>
						<h3><a class="<?php echo $thickbox_class; ?>" href="<?php echo $activate_link; ?>"><?php echo $title; ?></a></h3>
						<p><?php echo $description; ?></p>
						<?php if ( $tags ) { ?>
							<p><?php _e('Tags:'); ?> <?php echo join(', ', $tags); ?></p>
						<?php } ?>
						<span class="action-links"><a href="<?php echo $preview_link; ?>" class="<?php echo $thickbox_class; ?>" title="<?php echo $preview_text; ?>"><?php _e('Preview'); ?></a> <a href="<?php echo $activate_link; ?>" title="<?php echo $activate_text; ?>"><?php _e('Activate'); ?></a></span>
	
					<?php }
				} ?>
				</td>
			<?php } // end foreach $cols ?>
			</tr>
			<?php } // end foreach $table ?>
			</table>
		<?php } ?>

		<br class="clear" />

		<?php if ( $page_links ) { ?>
			<div class="tablenav">
			<?php echo "<div class='tablenav-pages'>$page_links_text</div>"; ?>
			<br class="clear" />
			</div>
		<?php } ?>
		<br class="clear" />
			
			
<?php
		
		
		
	} else { // old WordPress (temporary -- to remove in WP-United v0.8)
	?>
		<div class="wrap">
		<h2><?php _e('Your Current Theme'); ?></h2>
		<div id="currenttheme" style="margin-bottom: 190px;" >
		<?php if ( $screenshot ) : ?>
		<img src="<?php echo get_option('siteurl') . '/' . $stylesheet_dir . '/' . $screenshot; ?>" alt="<?php _e('Current theme preview'); ?>" />
		<?php endif; ?>
		<h3><?php printf(__('%1$s %2$s by %3$s'), $title, $version, $author) ; ?></h3>
		<p><?php echo $description; ?></p>

		</div>

		<h2><?php _e('Available Themes'); ?></h2>
		<?php if ( 1 < count($themes) ) { ?>

		<?php
		$style = '';

		$theme_names = array_keys($themes);
		natcasesort($theme_names);

		foreach ($theme_names as $theme_name) {
			if ( $theme_name == $user_theme )
				continue;
			$template = $themes[$theme_name]['Template'];
			$stylesheet = $themes[$theme_name]['Stylesheet'];
			$title = $themes[$theme_name]['Title'];
			$version = $themes[$theme_name]['Version'];
			$description = $themes[$theme_name]['Description'];
			$author = $themes[$theme_name]['Author'];
			$screenshot = $themes[$theme_name]['Screenshot'];
			$stylesheet_dir = $themes[$theme_name]['Stylesheet Dir'];
			$activate_link = wp_nonce_url("admin.php?page=wp-united.$phpEx&amp;noheader=true&amp;wpu_action=activate&amp;template=$template&amp;stylesheet=$stylesheet", 'wp-united-switch-theme_' . $template);
		?>
		<div class="available-theme">
		<h3><a href="<?php echo $activate_link; ?>"><?php echo "$title $version"; ?></a></h3>

		<a href="<?php echo $activate_link; ?>" class="screenshot">
		<?php if ( $screenshot ) : ?>
		<img src="<?php echo get_option('siteurl') . '/' . $stylesheet_dir . '/' . $screenshot; ?>" alt="" />
		<?php endif; ?>
		</a>

		<p><?php echo $description; ?></p>
		</div>
		<?php } // end foreach theme_names ?>

		<?php }

	 } ?>

		<h2><?php echo $phpbbForum->lang['wpu_more_themes_head']; ?></h2>
		<p><?php echo $phpbbForum->lang['wpu_more_themes_get']; ?></p>

		</div>

<?php
}



/**
 * If Style switching is allowed, returns the template for the current author's blog
 * We could do all this much later, in the template loader, but it is safer here so we load in all template-specific widgets, etc.
 */
function wpu_get_template($default) {
	global $wpSettings;
	if ( !empty($wpSettings['allowStyleSwitch']) ) {
		//The first time this is called, wp_query, wp_rewrite, haven't been set up, so we can't see what kind of page it's gonna be
		// so set them up now
		if ( !defined('TEMPLATEPATH') && !isset($GLOBALS['wp_the_query']) ) {
			$GLOBALS['wp_the_query'] =& new WP_Query();
			$GLOBALS['wp_query']     =& $GLOBALS['wp_the_query']; 
			$GLOBALS['wp_rewrite']   =& new WP_Rewrite();
			$GLOBALS['wp']           =& new WP(); 
			$GLOBALS['wp']->init(); 
			$GLOBALS['wp']->parse_request(); 
			$GLOBALS['wp']->query_posts(); 
		}

		if ( $authorID = wpu_get_author() ) { 
			$wpu_templatedir = get_user_meta($authorID, 'WPU_MyTemplate', true);
			$wpu_theme_path = get_theme_root() . "/$wpu_templatedir";
			if ( (file_exists($wpu_theme_path)) && (!empty($wpu_templatedir)) )	{
				return $wpu_templatedir;
			}
		} 
	}
	return $default;
}

/**
 * If Style switching is allowed, returns the stylesheet for the current author's blog
 * 
 */
function wpu_get_stylesheet($default) {
	global $wp_query, $wpSettings;
	if ( !empty($wpSettings['allowStyleSwitch']) ) {
		if ( $authorID = wpu_get_author() ) { 
			$wpu_stylesheetdir = get_user_meta($authorID, 'WPU_MyStylesheet', true);
			$wpu_theme_path = get_theme_root() . "/$wpu_stylesheetdir";
			if ( (file_exists($wpu_theme_path)) && (!empty($wpu_stylesheetdir)) )	{
				return $wpu_stylesheetdir;
			}
		}
	} 
	return $default;
}


/**
 * Called whenever a post is edited
 * Prevents an edited post from showing up on the blogs homepage
 * And allows us to differentiate between edits and new posts for cross-posting
 */
function wpu_justediting() {
	define('suppress_newpost_action', TRUE);
}

/**
 * Catches posts scheduled for future publishing
 * Since these posts won't retain the cross-posting HTTP vars, we add a post meta to future posts
 */
function wpu_capture_future_post($postID, $post) {
	global $wpSettings, $phpbbForum;
	
	if ( ($post->post_status == 'future') && (!empty($wpSettings['integrateLogin'])) ) {
		if ( ($phpbbForum->user_logged_in()) && (!empty($wpSettings['xposting'])) ) {
			// If x-post forcing is turned on, we don't need to do anything
			if( $wpSettings['xpostforce'] == -1) {
				if ( (isset($_POST['sel_wpuxpost'])) && (isset($_POST['chk_wpuxpost'])) ) {
					
					$forumID = (int)$_POST['sel_wpuxpost'];
					
					//only needs doing once
					if(get_post_meta($postID, '_wpu_future_xpost', true) === $forumID) {
						return;
					}
					
					// Need to check authority here -- as we won't know for sure when the time comes to xpost
					$can_crosspost_list = wpu_forum_xpost_list(); 
					
					if ( !in_array($forumID, (array)$can_crosspost_list['forum_id']) ) { 
						return;
					}
					update_post_meta($postID, '_wpu_future_xpost', $forumID);
					update_post_meta($postID, '_wpu_future_ip', $phpbbForum->get_userip());
				}
			} else {
				update_post_meta($postID, '_wpu_future_ip', $phpbbForum->get_userip());
			}
		}
	}
}

/**
 * Called when a post is transitioned from future to published
 * Since wp-cron could be invoked by any user, we treat logged in status etc differently
 */
function wpu_future_to_published($post) {
	global $wpSettings;
	define("WPU_JUST_POSTED_{$postID}", TRUE);
	wpu_newpost($post->ID, $post, true);
}

/*
 * Called whenever a new post is published.
 * Updates the phpBB user table with the latest author ID, to facilitate direct linkage via blog buttons
 * Also handles cross-posting
 */

function wpu_newpost($post_ID, $post, $future=false) {
	global $wpSettings, $phpbbForum, $phpbb_root_path;
	
	if( (!$future) && (defined("WPU_JUST_POSTED_{$postID}")) ) {
		return;
	}
	
	$did_xPost = false;

	if (($post->post_status == 'publish' ) || $future) { 
		if (!defined('suppress_newpost_action')) { //This should only happen ONCE, when the post is initially created.
			update_user_meta($post->post_author, 'wpu_last_post', $post_ID); 
		} 

		if ( !empty($wpSettings['integrateLogin']))  {
			global $db, $user, $phpEx; 
			
			$fStateChanged = $phpbbForum->foreground();
			
			// Update blog link column
			/**
			 * @todo this doesn't need to happen every time
			 */
			if ( !empty($post->post_author) ) {
				$sql = 'UPDATE ' . USERS_TABLE . ' SET user_wpublog_id = ' . $post->post_author . " WHERE user_wpuint_id = '{$post->post_author}'";
				if (!$result = $db->sql_query($sql)) {
					wp_die($phpbbForum->lang['WP_DBErr_Retrieve']);
				}
				$db->sql_freeresult($result);
			}
			
			
			if ( (($phpbbForum->user_logged_in()) || $future) && (!empty($wpSettings['xposting'])) ) {
				$did_xPost = wpu_do_crosspost($post_ID, $post, $future);
			} 

			define('suppress_newpost_action', TRUE);
		}
		$phpbbForum->restore_state($fStateChanged);
	}

}

/**
 * Returns the name of the current user's blog
 */
function wpu_blogname($default) {
	global $wpSettings, $user_ID, $phpbbForum, $adminSetOnce;
	if ( ((!empty($wpSettings['usersOwnBlogs'])) || ((is_admin()) && (!$adminSetOnce)))  ) {
		$authorID = wpu_get_author();
		if ($authorID === FALSE) {
			if ( is_admin() ) {
				$authorID = $user_ID;
				$adminSetOnce = 1; //only set once, for title
			}
		}	
		if ( !empty($authorID) ) {
			$blog_title = get_user_meta($authorID, 'blog_title', true);
			if ( empty($blog_title) ) {
				if ( !is_admin() ) {
					$blog_title = $phpbbForum->lang['default_blogname']; 
				}
			}
			$blog_title = wpu_censor($blog_title);
			if ( !empty($blog_title) ) {
				return $blog_title;
			}
		}
	}
	return $default;
}

/**
 * Returns the tagline of the current user's blog
 */
function wpu_blogdesc($default) {
	global $wpSettings, $phpbbForum;
	if ( !empty($wpSettings['usersOwnBlogs']) ) {
		$authorID = wpu_get_author();
		if ( !empty($authorID) ) {
			$blog_tagline = get_user_meta($authorID, 'blog_tagline', true);
			if ( empty($blog_tagline) ) {
				$blog_tagline = $phpbbForum->lang['default_blogdesc'];
			}
			$blog_tagline = wpu_censor($blog_tagline);
			return $blog_tagline;
		}
	}
	return $default;
}

/**
 * Returns the URL of the current user's blog
 */
function wpu_homelink($default) {
	global $wpSettings, $user_ID, $wpu_done_head, $altered_link;
	if ( ($wpu_done_head && !$altered_link) || ($default=="wpu-activate-theme")  ) {

		if ( !empty($wpSettings['usersOwnBlogs']) ) {

			$altered_link = TRUE; // prevents this from becoming recursive -- we only want to do it once anyway

			if ( !is_admin() ) {
				$authorID = wpu_get_author();
			} else {
				$authorID = $user_ID;
			}
			if ( !empty($authorID) ) { 
				if(count_user_posts($authorID)) { // only change URL if author has posts
					$blog_url = get_author_posts_url($authorID); 
					$blog_url = ( $blog_url[strlen($blog_url)-1] == "/" ) ? substr($blog_url, 0, -1) : $blog_url; //kill trailing slash
				}
				if ( empty($blog_url) ) {
					$blog_url = $default; 
				}
				return $blog_url;
			}
		}
	}
	return $default;
}




/**
 *  Figure out which author's blog this is. Caches the result.
 */
function wpu_get_author() {
	global $wp_query, $wpuCachedAuthor;
	$authorID = FALSE;
	if ( empty($wpuCachedAuthor) ) {
		if ( is_author() ) {	
			$authorID = $wp_query->query_vars['author'] ; 
		} elseif ( is_single() ) {
			$authorID = $wp_query->get_queried_object();
			$authorID = $authorID->post_author;
		} elseif ( isset($_GET['author'] )) { 
			$authorID = (integer)$_GET['author']; 
		} 
		$wpuCachedAuthor = $authorID;
	} else {
		$authorID = $wpuCachedAuthor;
	}
	return $authorID;
}


/**
 * Sets wpu_done_head to true, so we can alter things like the home link without worrying.
 * (before the <HEAD>, we don't want to modify any links)
 * We also add the blog homepage stylesheet here, and add the head marker for 
 * template integration when WordPress CSS is first.
*/
function wpu_done_head() {
	global $wpu_done_head, $wpSettings, $wp_the_query, $wpuIntegrationMode;
	$wpu_done_head = true; 
	//add the frontpage stylesheet, if needed: 
	if ( (!empty($wpSettings['blUseCSS'])) && (!empty($wpSettings['useBlogHome'])) ) {
		echo '<link rel="stylesheet" href="' . $wpSettings['wpPluginUrl'] . 'theme/wpu-blogs-homepage.css" type="text/css" media="screen" />';
	}
	if ( ($wpuIntegrationMode == 'template-p-in-w') && (!PHPBB_CSS_FIRST) ) {
		echo '<!--[**HEAD_MARKER**]-->';
	}
	
	
}

/**
 * Add a marker -- this is the last chance we have to prevent the home link from being changed
 */
function wpu_loop_entry() {
	$GLOBALS['altered_link'] = TRUE;
}

/**
 * Turns our page place holder into the blog list page, or the forum-in-a-full-page
 */
function wpu_content_parse_check($postContent) {
	if (! defined('PHPBB_CONTENT_ONLY') ) {
		if ( !(strpos($postContent, "<!--wp-united-home-->") === FALSE) ) {
			$postContent = get_wpu_blogs_home();
		} else {
			$postContent = wpu_censor($postContent);
		}
	} else {
		global $innerContent, $wpuOutputPreStr, $wpuOutputPostStr;
		$postContent = "<!--[**INNER_CONTENT**]-->";
	}
	return $postContent;
}


/**
 * Handles parsing of posts through the phpBB word censor.
 * We also use this hook to suppress everything if this is a forum page.
*/
function wpu_censor($postContent) {
	global $wpUnited, $wpSettings, $phpbbForum;
	
	if(!$wpUnited->is_phpbb_loaded()) {
		return $postContent;
	}
	//if (! defined('PHPBB_CONTENT_ONLY') ) {  Commented out as we DO want this to to work on a full reverse page.
		if ( !is_admin() ) {
			if ( !empty($wpSettings['phpbbCensor'] ) ) { 
				return $phpbbForum->censor($postContent);
			}
		}
		return $postContent;
	//}
}

/**
 * Alters the where clause of the sql for previous/Next post lookup, to ensure we stay on the same author blog
 */
function wpu_prev_next_post($where) {
	global $wpSettings, $post;
	$author = $post->post_author;
	
	if ( !empty($wpSettings['usersOwnBlogs']) ) {
		$where = str_replace("AND post_type = 'post'", "AND post_author = '$author' AND post_type = 'post'", $where); 
	}	
	return $where;
}

/**
 * If users can have own blogs, uploads attachments to users' own directories.
 * i.e. uploads/username or uploads/username/yyyyy/mm
 * This prevents users from browsing other users' media
 */
function wpu_user_upload_dir($default) {
	global $wpSettings, $phpbbForum;

	if ( !empty($wpSettings['integratelogin']) ) {
		global $user_ID, $phpbbForum;
		$usr = get_userdata($user_ID);
		$usrDir = $usr->user_login;
		if ( get_option('uploads_use_yearmonth_folders')) {
			$inputDir = explode('/', $default['path']);
			$inputUrl = explode('/', $default['url']);
			array_splice($inputDir, count($inputDir) - 2, 0, $usrDir);
			array_splice($inputUrl, count($inputUrl) - 2, 0, $usrDir);
			$inputDir = implode('/', $inputDir);
			$inputUrl = implode('/', $inputUrl);
		} else {
			$inputDir = $default['path'] . '/'.$usrDir;
			$inputUrl = $default['url'] . '/'.$usrDir;
		}
		if ( !wp_mkdir_p($inputDir) ) {
			$message = sprintf($phpbbForum->lang['wpu_user_media_dir_error'], $dir);
			return array('error' => $message);
		}
		$default['path'] = $inputDir;
		$default['url'] = $inputUrl;
	}
	return $default;
}

/**
 * Adds a filter if we are browsing attachments if users have own blogs but don't have 'edit' permissions
 */
function wpu_browse_attachments() {
	global $user_ID, $wpSettings;

	if ( (!empty($wpSettings['integrateLogin'])) && (!current_user_can('edit_post', (int) $ID)) ) {
		add_filter( 'posts_where', 'wpu_attachments_where' );
	}
}

/**
 * Filters attachments (media) so they are for the current user only
 */
function wpu_attachments_where($where) {
	global $user_ID, $phpbbForum;
	if (!empty($user_ID) ) {
		return $where . " AND post_author = '" . (int)$user_ID . "'";
	} else {
		die($phpbbForum->lang['wpu_access_error']);
	}
}

/**
 * Returns an author's feed link on the main page if users can have own blogs.
 */
function wpu_feed_link($link) {
	global $wpSettings;
	if ( !empty($wpSettings['usersOwnBlogs']) ) { 
		$authorID = wpu_get_author();
		if ( (!strstr($link, 'comment')) ) {
			$link = get_author_rss_link(FALSE, $authorID, '');
		} else {
		//	get author RSS link for comments	
		}
	}
	return $link;
}

/**
 * Redirects to the integrated page, in case WordPress has been accessed directly.
 * This will probably piss some people off -- but it's better than people accessing the wrong page and insisting it is screwed up.
 * @todo this action is currently disabled -- we should just check for login page for now
 */
function wpu_must_integrate() {
	if ( (!defined('WP_UNITED_ENTRY')) && (!is_admin()) ) {
		if ( defined('IN_PHPBB') ) { //try to avoid infinitely redirecting loops
			wp_redirect(get_option('home'));
			exit();
		}
	}
}


/**
 * Clears phpbb's cache of WP header/footer.
 * We need to do this whenever the main WP theme is changed,
 * because when WordPress header/footer cache are called from phpBB, we have
 * no way of knowing what the theme should be a WordPress is not invoked
 */
function wpu_clear_header_cache() {
	global $wpSettings, $phpEx;
	require_once($wpSettings['wpPluginPath'] . 'cache.' . $phpEx);
	$wpuCache = WPU_Cache::getInstance();
	$wpuCache->template_purge();
}

/**
 * Add box to the write/(edit) post page.
 */
function wpu_add_postboxes() {
	global $can_xpost_forumlist, $already_xposted, $phpbbForum, $wpSettings;
?>
	<div id="wpuxpostdiv" class="inside">
	<?php if ($already_xposted) echo '<strong><small>' . sprintf($phpbbForum->lang['wpu_already_xposted'], $already_xposted['topic_id']) . "</small></strong><br /> <input type=\"hidden\" name=\"wpu_already_xposted_post\" value=\"{$already_xposted['post_id']}\" /><input type=\"hidden\" name=\"wpu_already_xposted_forum\" value=\"{$already_xposted['forum_id']}\" />"; ?>
	<label for="wpu_chkxpost" class="selectit">
		<input type="checkbox" <?php if ($already_xposted) echo 'disabled="disabled" checked="checked"'; ?>name="chk_wpuxpost" id="wpu_chkxpost" value="1001" />
		<?php echo $phpbbForum->lang['wpu_xpost_box_title']; ?><br />
	</label><br />
	<label for="wpu_selxpost">Select Forum:</label><br />
		<select name="sel_wpuxpost" id="wpu_selxpost" <?php if ($already_xposted) echo 'disabled="disabled"'; ?>> 
		<?php
			if ($already_xposted) {
				echo "<option value=\"{$already_xposted['forum_id']}\">{$already_xposted['forum_name']}</option>";
			} else {
				foreach ( $can_xpost_forumlist['forum_id'] as $key => $value ) {
					echo "<option value=\"{$value}\" ";
					echo ($key == 0) ? 'selected="selected"' : '';
					echo ">{$can_xpost_forumlist['forum_name'][$key]}</option>";
				}
			} ?>
			</select>
	
			 <?php if($wpSettings['xposttype'] == 'ASKME') {
				$excerptState = 'checked="checked"';
				$fullState = '';
				if (isset($_GET['post'])) {
					$postID = (int)$_GET['post'];
					if(get_post_meta($postID, '_wpu_posttype', true) != 'excerpt') {
						$fullState = 'checked="checked"';
						$excerptState = '';
					}
				}
				echo '<br /><input type="radio" name="rad_xpost_type" value="excerpt" ' . $excerptState . ' />' . $phpbbForum->lang['wpu_excerpt'] . '<br />';
				echo '<input type="radio" name="rad_xpost_type" value="fullpost" ' . $fullState . ' />' . $phpbbForum->lang['wpu_fullpost'];
			} ?>

	</div>
<?php
}
/**
 * Adds a "Force cross-posting" info box
 */
function wpu_add_forcebox($forumName) {
	global $forceXPosting, $phpbbForum, $wpSettings;

	$showText =  (wpu_get_xposted_details()) ? $phpbbForum->lang['wpu_forcexpost_update'] : $phpbbForum->lang['wpu_forcexpost_details'];

?>
	<div id="wpuxpostdiv" class="inside">
	<p> <?php echo sprintf($showText, $forceXPosting); ?></p>
	<?php if($wpSettings['xposttype'] == 'ASKME') {
				$excerptState = 'checked="checked"';
				$fullState = '';
				if (isset($_GET['post'])) {
					$postID = (int)$_GET['post'];
					if(get_post_meta($postID, '_wpu_posttype', true) != 'excerpt') {
						$fullState = 'checked="checked"';
						$excerptState = '';
					}
				}
				echo '<br /><input type="radio" name="rad_xpost_type" value="excerpt" ' . $excerptState . ' />' . $phpbbForum->lang['wpu_excerpt'] . '<br />';
				echo '<input type="radio" name="rad_xpost_type" value="fullpost" ' . $fullState . ' />' . $phpbbForum->lang['wpu_fullpost'];
			} ?>
	</div>
<?php
}

/**
 *  Here we decide whether to display the cross-posting box, and store the permissions list in global vars for future use.
 * For WP >= 2.5, we set the approproate callback function. For older WP, we can go directly to the func now.
 */
function wpu_add_meta_box() {
	global $phpbbForum, $wpSettings, $can_xpost_forumlist, $already_xposted;
	// this func is called early
	if (preg_match('/\/wp-admin\/(post.php|post-new.php|press-this.php)/', $_SERVER['REQUEST_URI'])) {
		if ( (!isset($_POST['action'])) && (($_POST['action'] != "post") || ($_POST['action'] != "editpost")) ) {
	
			//Add the cross-posting box if enabled and the user has forums they can post to
			if ( !empty($wpSettings['xposting']) && !empty($wpSettings['integrateLogin']) ) { 
				
				if($wpSettings['xpostforce'] > -1) {
					// Add forced xposting info box
					global $forceXPosting;
					$forceXPosting = wpu_get_forced_forum_name($wpSettings['xpostforce']);
					if($forceXPosting !== false) {
						add_meta_box('postWPUstatusdiv', __($phpbbForum->lang['wpu_forcexpost_box_title'], 'wpu-cross-post'), 'wpu_add_forcebox', 'post', 'side');
					}
				} else {	
					// Add xposting choice box
					if ( !($already_xposted = wpu_get_xposted_details()) ) { 
						$can_xpost_forumlist = wpu_forum_xpost_list(); 
					}
			
					if ( (sizeof($can_xpost_forumlist)) || $already_xposted ) {
						add_meta_box('postWPUstatusdiv', __($phpbbForum->lang['wpu_xpost_box_title'], 'wpu-cross-post'), 'wpu_add_postboxes', 'post', 'side');
					}
				}
			}
		}
	}
}



	


/**
 * Add script to our user blog theme selection page
 */
function wpu_prepare_admin_pages() {
	if ( isset($_GET['page']) ) {
		if ($_GET['page'] == 'wp-united-theme-menu') {
			add_thickbox();
			wp_enqueue_script( 'theme-preview' );
			
		}
	}
}


/**
* Function 'get_avatar()' - Retrieve the phpBB avatar of a user
* @since WP-United 0.7.0
*/

function wpu_get_phpbb_avatar($avatar, $id_or_email, $size = '96', $default = '', $alt = false ) { 
   global $wpSettings, $phpbbForum;
   if (empty($wpSettings['integrateLogin'])) { 
      return $avatar;
   }

   $safe_alt = esc_attr( $phpbbForum->lang['USER_AVATAR'] );

   if ( !is_numeric($size) )
      $size = '96';

   if ( !is_numeric($size) )
      $size = '96';
   // Figure out if this is an ID or e-mail --sourced from WP's pluggables.php
   $email = '';
   if ( is_numeric($id_or_email) ) {
      $id = (int) $id_or_email;
      $user = get_userdata($id);
   } elseif ( is_object($id_or_email) ) {
      if ( !empty($id_or_email->user_id) ) {
		  // $id_or_email is probably a comment object
         $user = get_userdata($id_or_email->user_id);
      } 
   }

   if($user) {
      // use default WordPress or WP-United image
      if(!$image = avatar_create_image($user)) { 
         if(stripos($avatar, 'blank.gif') !== false) {
            $image = $wpSettings['wpPluginUrl'] . 'images/wpu_no_avatar.gif';
         } else {
            return $avatar;
         }
      } 
   } else {
      if(stripos($avatar, 'blank.gif') !== false) {
          $image = $wpSettings['wpPluginUrl'] . 'images/wpu_unregistered.gif';
       } else {
         return $avatar;
      }
   }
   return "<img alt='{$safe_alt}' src='{$image}' class='avatar avatar-{$size}' height='{$size}' width='{$size}' />";
}


/**
 * Function 'wpu_smilies' replaces the phpBB smilies' code with the corresponding smilies into comment text
 * @since WP-United 0.7.0
 */
function wpu_smilies($postContent, $max_smilies = 0) {
	global $phpbbForum, $wpSettings;
	
	if ( !empty($wpSettings['phpbbSmilies'] ) ) { 
		static $match;
		static $replace;
		global $db;
	

		// See if the static arrays have already been filled on an earlier invocation
		if (!is_array($match)) {
		
			$fStateChanged = $phpbbForum->foreground();
			
			$result = $db->sql_query('SELECT code, emotion, smiley_url FROM '.SMILIES_TABLE.' ORDER BY smiley_order', 3600);

			while ($row = $db->sql_fetchrow($result)) {
				if (empty($row['code'])) {
					continue; 
				} 
				$match[] = '(?<=^|[\n .])' . preg_quote($row['code'], '#') . '(?![^<>]*>)';
				$replace[] = '<!-- s' . $row['code'] . ' --><img src="' . $phpbbForum->url . '/images/smilies/' . $row['smiley_url'] . '" alt="' . $row['code'] . '" title="' . $row['emotion'] . '" /><!-- s' . $row['code'] . ' -->';
			}
			$db->sql_freeresult($result);
			
			$phpbbForum->restore_state($fStateChanged);
			
		}
		if (sizeof($match)) {
			if ($max_smilies) {
				$num_matches = preg_match_all('#' . implode('|', $match) . '#', $postContent, $matches);
				unset($matches);
			}
			// Make sure the delimiter # is added in front and at the end of every element within $match
			$postContent = trim(preg_replace(explode(chr(0), '#' . implode('#' . chr(0) . '#', $match) . '#'), $replace, $postContent));
		}
	}
	return $postContent;
}




/**
 * Adds any required inline JS (for language strings)
 */
function wpu_inline_js() {
	global $wpSettings, $phpbbForum;
	
	// Rather than outputting the script, we just signpost any language strings we will need
	// The scripts themselves are already enqueud.
	if ( !empty($wpSettings['phpbbSmilies'] ) ) {
		echo "\n<script type=\"text/javascript\">//<![CDATA[\nvar wpuLang ={";
		$langStrings = array('wpu_more_smilies', 'wpu_less_smilies', 'wpu_smiley_error');
		for($i=0; $i<sizeof($langStrings);$i++) {
			if($i>0) {
				echo ',';
			}
			echo "'{$langStrings[$i]}': '" . str_replace("\\\\'", "\\'", str_replace("'", "\\'",  $phpbbForum->lang[$langStrings[$i]])) . "'";
		}
		echo "} // ]]>\n</script>";
	}
}

/**
* Function 'wpu_fix_blank_username()' - Generates a username in WP when the sanitized username is blank,
* as phpbb is more liberal in user naming
* Originally by Wintermute
* If the sanitized user_login is blank, create a random
* username inside WP. The user_login begins with WPU followed
* by a random number (1-10) of digits between 0 & 9
* Also, check to make sure the user_login is unique
* @since WP-United 0.7.1
*/
function wpu_fix_blank_username($user_login) {
	global $wpSettings;

	if (!empty($wpSettings['integrateLogin'])) { 
	    if ( empty($user_login) ){
			$foundFreeName = FALSE;
			while ( !$foundFreeName ) {
				$user_login = "WPU";
				srand(time());
				for ($i=0; $i < (rand()%9)+1; $i++)
					$user_login .= (rand()%9);
				if ( !username_exists($user_login) )
					$foundFreeName = TRUE;
			}
		}
	}
	return $user_login;
}



/**
* Under consideration for future rewrite: Function 'wpu_validate_username_conflict()' - Handles the conflict between validate_username
* in WP & phpBB. This is only really a problem in integrated pages when naughty WordPress plugins pull in
* registration.php. 
* 
* These functions should NOT collide in usage -- only in namespace. If user integration is turned on, we don't need
* WP's validate_username. 
* 
* Furthermore, if phpbb_validate_username is defined, then we know we most likely need to use the phpBB version.
* 
* We unfortunately cannot control their usage -- phpbb expects 2 arguments, whereas WordPress only expects one.
* 
* Therefore here we just try to avoid namespace errors. If they are actually invoked while renamed, the result is undefined
*/

function wpu_validate_username_conflict($wpValdUser, $username) {
	global $phpbbForum;
	if($phpbbForum->get_state() == 'phpbb') {
		if(function_exists('phpbb_validate_username')) {
			return phpbb_validate_username($username, false);
		}
	}
	return $wpValdUser;
}

/**
 * Checks username and e-mail requested for a new registration.
 * Validates against phpBB if user integration is working.
 * @param string $username username
 * @param string $email e-mail
 * @param WP_Error $errors WordPress error object
 */
function wpu_check_new_user($username, $email, $errors) {
	$result = wpu_validate_new_user($username, $email, $errors);
	
	if($result !== false) {
		$errors = $result;
	}
}

/**
 * checks a new registration 
 * This occurs after the account has been created, so it is only for naughty plugins that
 * leave no other way to intercept them.
 * If it is found to be an erroneous user creation, then we remove the newly-added user.
 * This action is removed by WP-United when adding a user, so we avoid unsetting our own additions
 */
add_action('user_register', 'wpu_check_new_user_after', 10, 1); 
function wpu_check_new_user_after($userID) { 
		global $wpSettings, $phpbbForum, $wpUnited, $wpuJustCreatedUser;
	
	
		/*
		 * if we've already created a user in this session, 
		 * it is likely an error from a plugin calling the user_register hook 
		 * after wp_insert_user has already called it. The Social Login plugin does this
		 * 
		 * At any rate, it is pointless to check twice
		 */
		if($wpuJustCreatedUser == $userID) {
				return;
		}

		// some registration plugins don't init WP. This is enough to get us a phpBB env
		$wpUnited->init_plugin();
			
		// neeed some user add / delete functions
		if ( ! defined('WP_ADMIN') ) {
			require_once(ABSPATH . 'wp-admin/includes/user.php');
		}


		if (!empty($wpSettings['integrateLogin'])) { 
			
			$errors = new WP_Error();
			$user = get_userdata($userID);
			

			$result = wpu_validate_new_user($user->user_login, $user-->user_email , $errors);

			if($result !== false) { 
				// An error occurred validating the new WP user, remove the user.
				
				wp_delete_user($userID,  0);
				$message = '<h1>' . __('Error:') . '</h1>';
				$message .= '<p>' . implode('</p><p>', $errors->get_error_messages()) . '</p><p>';
				$message .= __('Please go back and try again, or contact an administrator if you keep seeing this error.') . '</p>';
				wp_die($message);
				
				exit();
			} else { 
	
				// create new integrated user in phpBB to match
				$phpbbID = wpu_create_phpbb_user($userID);
				$wpuJustCreatedUser = true;
			}
			
		}
}

/**
 * Validates a new or prospective WordPress user in phpBB
 * @param string $username username
 * @param string $email e-mail
 * @param WP_Error $errors WordPress error object
 * @return bool|WP_Error false (on success) or modified WP_Error object (on failure)
 */
function wpu_validate_new_user($username, $email, $errors) {
	global $wpSettings, $phpbbForum;
	$foundErrors = 0;
	if (!empty($wpSettings['integrateLogin'])) {
		if(function_exists('phpbb_validate_username')) {
			$fStateChanged = $phpbbForum->foreground();
			$result = phpbb_validate_username($username, false);
			$emailResult = validate_email($email);
			$phpbbForum->restore_state($fStateChanged);

			if($result !== false) {
				switch($result) {
					case 'INVALID_CHARS':
						$errors->add('phpbb_invalid_chars', __('The username contains invalid characters'));
						$foundErrors++;
						break;
					case 'USERNAME_TAKEN':
						$errors->add('phpbb_username_taken', __('The username is already taken'));
						$foundErrors++;
						break;
					case 'USERNAME_DISALLOWED':
						default;
						$errors->add('phpbb_username_disallowed', __('The username you chose is not allowed'));
						$foundErrors++;
						break;
				}
			}
			
			if($emailResult !== false) {
				switch($emailResult) {
					case 'DOMAIN_NO_MX_RECORD':
						$errors->add('phpbb_invalid_email_mx', __('The email address does not appear to exist (No MX record)'));
						$foundErrors++;
						break;
					case 'EMAIL_BANNED':
						$errors->add('phpbb_email_banned', __('The e-mail address is banned'));
						$foundErrors++;
						break;
					case 'EMAIL_TAKEN':
						$errors->add('phpbb_email_taken', __('The e-mail address is already taken'));
						break;
					case 'EMAIL_INVALID':
						default;
						$errors->add('phpbb_invalid_email', __('The email address is invalid'));
						$foundErrors++;
						break;									
				}
			}

		}
	}
	
	return ($foundErrors) ? $errors : false;
	
	
}



add_filter('plugin_row_meta', 'wpu_pluginrow_link', 10, 2);
function wpu_pluginrow_link($links, $file) {

	if ($file == 'wp-united/wp-united.php') {
		$links[] = '<a href="admin.php?page=wp-united-setup">' . __('Setup / Status') . '</a>';
	}
	return $links;
}

function wpu_deactivate() {
	// No actions currently defined
	wpu_uninstall();  /** TEMP FOR RESETTING WHILE TESTING **/
}

/**
 * Removes all WP-United settings.
 * As the plugin is deactivated at this point, we can't reliably uninstall from phpBB (yet)
 */
function wpu_uninstall() {
	
	$forum_page_ID = get_option('wpu_set_forum');
	if ( !empty($forum_page_ID) ) {
		@wp_delete_post($forum_page_ID);
	}
		
	$wpSettings = get_option('wpu-settings');
	
	
	delete_option('wpu_set_forum');
	delete_option('wpu-settings');
	delete_option('wpu-last-run');
	delete_option('wpu-enabled');
	delete_option('widget_wp-united-loginuser-info');
	delete_option('widget_wp-united-latest-topics');
	delete_option('widget_wp-united-latest-posts');
	
	
	/*
	if(isset($wpSettings['phpbb_path'])) {
		
		global $db;
		
		$phpbb_root_path = $wpSettings['phpbb_path'];
		$phpEx = substr(strrchr(__FILE__, '.'), 1);
	
		define('IN_PHPBB', true);
		define('WPU_UNINSTALLING', true);
		
		$phpEx = substr(strrchr(__FILE__, '.'), 1);
		
		$commonLoc = $phpbb_root_path . 'common.' . $phpEx;
		
		if(file_exists($commonLoc)) {
			include($phpbb_root_path . 'common.' . $phpEx);
			
			$sql = 'ALTER TABLE ' . USERS_TABLE . ' 
						  DROP user_wpuint_id';
			$db->sql_query($sql);
			
			$sql = 'ALTER TABLE ' . USERS_TABLE . '
						DROP user_wpublog_id';
			$db->sql_query($sql);
					
			$sql = 'ALTER TABLE ' . POSTS_TABLE . ' 
						DROP post_wpu_xpost';
			$db->sql_query($sql);
			
		}
	} */
	
	
}



register_deactivation_hook('wp-united/wp-united.php', 'wpu_deactivate');
register_uninstall_hook('wp-united/wp-united.php', 'wpu_uninstall');


if ( isset($_GET['page']) ) {
	if ($_GET['page'] == 'wp-united-theme-menu') {
		add_action('admin_init', 'wpu_prepare_admin_pages');
	}
}



?>
