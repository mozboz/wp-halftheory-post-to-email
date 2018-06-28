<?php
/*
Plugin Name: Post to Email
Plugin URI: https://github.com/halftheory/wp-halftheory-post-to-email
GitHub Plugin URI: https://github.com/halftheory/wp-halftheory-post-to-email
Description: Post to Email
Author: Half/theory
Author URI: https://github.com/halftheory
Version: 1.0
Network: true
*/

/*
Available filters:
posttoemail_option_defaults(array)
posttoemail_deactivation(string $db_prefix)
posttoemail_uninstall(string $db_prefix)
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Post_To_Email_Plugin')) :
class Post_To_Email_Plugin {

	private $subclass = false;

	public function __construct($load_actions = true) {
		if (!class_exists('Post_To_Email')) {
			@include_once(dirname(__FILE__).'/includes/class-post-to-email.php');
		}
		if (class_exists('Post_To_Email')) {
			$this->subclass = Post_To_Email::init();
			if ($load_actions) {
				if (!class_exists('Post_To_Email_Actions')) {
					@include_once(dirname(__FILE__).'/includes/class-post-to-email-actions.php');
				}
				if (class_exists('Post_To_Email_Actions')) {
					new Post_To_Email_Actions;
				}
			}
		}
	}

	public static function init() {
		$plugin = new self;
		return $plugin;
	}

	public static function activation() {
		$plugin = new self(false);
		if (!$plugin->subclass) {
			return;
		}

		$plugin->subclass->cron_toggle();

		// add defaults if they don't exist
		$options = $plugin->subclass->get_option();
		if (empty($options)) {
			$blogname = $plugin->subclass->get_cache_main_blog_options('blogname', '');
			$option_defaults = array(
				'admin_email' => get_option('admin_email', ''),
				'opt_in' => 1,
				'default_interval' => 'monthly',
				'mail_from' => $blogname. '<'.get_option('admin_email', '').'>',
				'mail_subject' => '###BLOGNAME### Digest - ###DATE###',
				'mail_message_excerpt_length' => 250,
			);
			$option_defaults = apply_filters('posttoemail_option_defaults', $option_defaults);
            if ($plugin->subclass->update_options($option_defaults)) {
            	// ok
            }
        	else {
        		// error
        	}
		}

		return $plugin;
	}

	public static function deactivation() {
		$plugin = new self(false);
		if (!$plugin->subclass) {
			return;
		}

		$plugin->subclass->cron_toggle(false);

		apply_filters('posttoemail_deactivation', $plugin->subclass::$prefix);
		return;
	}

	public static function uninstall() {
		$plugin = new self(false);
		if (!$plugin->subclass) {
			return;
		}

		$plugin->subclass->cron_toggle(false);

		// remove options
		if (is_multisite()) {
			delete_site_option($plugin->subclass::$prefix);
			$sites = get_sites();
			foreach ($sites as $value) {
				delete_blog_option($value->blog_id, $plugin->subclass::$prefix);
			}
		}
		else {
			delete_option($plugin->subclass::$prefix);
		}
		// remove usermeta
		global $wpdb;
		$wpdb->query("DELETE FROM $wpdb->usermeta WHERE meta_key = '".$plugin->subclass::$prefix."'");

		apply_filters('posttoemail_uninstall', $plugin->subclass::$prefix);
		return;
	}

}
// Load the plugin.
add_action('init', array('Post_To_Email_Plugin', 'init'));
endif;

register_activation_hook(__FILE__, array('Post_To_Email_Plugin', 'activation'));
register_deactivation_hook(__FILE__, array('Post_To_Email_Plugin', 'deactivation'));
if (!function_exists('Post_To_Email_Plugin_uninstall')) {
	function Post_To_Email_Plugin_uninstall() {
		Post_To_Email_Plugin::uninstall();
	}
}
register_uninstall_hook(__FILE__, 'Post_To_Email_Plugin_uninstall');
?>