<?php
/*
Available filters:
halftheory_admin_menu_parent
posttoemail_admin_menu_parent
posttoemail_post_types
posttoemail_excluded_posts
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

if (!class_exists('Post_To_Email_Actions')) :
class Post_To_Email_Actions {

	public function __construct() {
		if (!class_exists('Post_To_Email')) {
			return;
		}
		$this->subclass = Post_To_Email::init();
		$this->plugin_name = $this->subclass::$plugin_name;
		$this->plugin_title = $this->subclass::$plugin_title;
		$this->prefix = $this->subclass::$prefix;

		// admin
		if (!$this->subclass->is_front_end()) {
			// menus
			add_action('current_screen', array($this,'current_screen'));
			add_action('admin_enqueue_scripts', array($this,'admin_enqueue_scripts'), 20);
			if (is_multisite()) {
				if (is_network_admin()) {
					add_action('network_admin_menu', array($this,'admin_menu'));
				}
				else {
					add_action('admin_menu', array($this,'admin_menu'));
				}
			}
			else {
				add_action('admin_menu', array($this,'admin_menu'));
			}
			// tabs_all tab_active
			if (strpos($this->subclass->get_current_uri(), $this->prefix) !== false) {
				$this->tabs_all = true;
				$tab_default = 'general';
				if (is_multisite()) {
					if (!is_network_admin() && !is_main_site()) {
						$this->tabs_all = false;
						$tab_default = 'post';
					}
				}
				$this->tab_active = (isset($_GET['tab']) ? $_GET['tab'] : $tab_default);
				if ($this->tab_active == 'test') {
					$this->subclass::$is_test = true;
				}
			}
		}

		// cron
		add_action($this->prefix.'_cron', array($this,'cron_action'));

		// stop if not active
		$active = $this->subclass->get_option('active', false);
		if (empty($active)) {
			return;
		}

		// actions
		// admin
		if (!$this->subclass->is_front_end()) {
			add_action('edit_user_profile', array($this,'edit_user_profile'), 20);
			add_action('show_user_profile', array($this,'edit_user_profile'), 20);
			add_action('edit_user_profile_update', array($this,'edit_user_profile_update'));
			add_action('personal_options_update', array($this,'edit_user_profile_update'));
			add_action('deleted_user', array($this,'deleted_user'));
		}
		// buddypress
		if ($this->subclass::$has_buddypress) {
			add_action('bp_notification_settings', array($this,'bp_notification_settings'));
		}
	}

	/* actions - admin */

	public function current_screen($current_screen) {
		$this->current_screen_id = $current_screen->id;
	}
	public function admin_enqueue_scripts() {
		if (strpos($this->current_screen_id, $this->prefix) === false) {
			return;
		}
		if ($this->tab_active == 'mail') {
			wp_enqueue_media();
			wp_enqueue_script($this->prefix.'-media', plugin_dir_url(dirname(__FILE__)).'assets/js/wp-media-editor.js', array('jquery'), null, false); // header
		}
	}

	public function admin_menu() {
		if (!is_array($GLOBALS['menu'])) {
			return;
		}

		$has_parent = false;
		$parent_slug = $this->prefix;
		$parent_name = apply_filters('halftheory_admin_menu_parent', 'Halftheory');
		$parent_name = apply_filters('posttoemail_admin_menu_parent', $parent_name);

		// set parent to nothing to skip parent menu creation
		if (empty($parent_name)) {
			add_options_page(
				$this->plugin_title,
				$this->plugin_title,
				'manage_options',
				$this->prefix,
				__CLASS__.'::menu_page'
			);
			return;
		}

		// find top level menu if it exists
	    foreach ($GLOBALS['menu'] as $value) {
	    	if ($value[0] == $parent_name) {
	    		$parent_slug = $value[2];
	    		$has_parent = true;
	    		break;
	    	}
	    }

		// add top level menu if it doesn't exist
		if (!$has_parent) {
			add_menu_page(
				$this->plugin_title,
				$parent_name,
				'manage_options',
				$parent_slug,
				__CLASS__.'::menu_page'
			);
		}

		// add the menu
		add_submenu_page(
			$parent_slug,
			$this->plugin_title,
			$this->plugin_title,
			'manage_options',
			$this->prefix,
			__CLASS__.'::menu_page'
		);
	}

	public function menu_page() {
 		global $title;
		?>
		<div class="wrap">
			<h2><?php echo $title; ?></h2>
		<?php
 		$plugin = new self;

        if ($_POST['save']) {
        	$save = function() use ($plugin) {
				// verify this came from the our screen and with proper authorization
				if (!isset($_POST[$plugin->plugin_name.'::menu_page'])) {
					return;
				}
				if (!wp_verify_nonce($_POST[$plugin->plugin_name.'::menu_page'], plugin_basename(__FILE__))) {
					return;
				}

        $updated = '<div class="updated"><p><strong>Options saved.</strong></p></div>';
        $error = '<div class="error"><p><strong>Error: There was a problem.</strong></p></div>';

				// test mail
				if ($plugin->tab_active == 'test') {
		      $updated = '<div class="updated"><p><strong>Options updated.</strong></p></div>';
					$has_error = false;
            if (isset($_POST[$plugin->prefix.'_test_user_id']) && !empty($_POST[$plugin->prefix.'_test_user_id'])) {
              if (isset($_POST[$plugin->prefix.'_test_send_to_admin']) || isset($_POST[$plugin->prefix.'_test_send_to_user'])) {
                  $updated = '<div class="updated"><p><strong>Mail sent.</strong></p></div>';
                  $options = $plugin->subclass->get_option();
                  $test_userdata = get_userdata($_POST[$plugin->prefix.'_test_user_id']);
      
                  $arr = $plugin->subclass->get_message_array($options, $test_userdata);
                  if ($arr) {
                    if (isset($_POST[$plugin->prefix.'_test_send_to_admin'])) {
                      $res = $plugin->subclass->mail($options, $options['admin_email'], $arr['subject'], $arr['message']);
                      if (!$res) {
                        $error = $plugin->subclass::class() . "->mail() function failed";
                        $has_error = true;
                      }
                    }
                    if (isset($_POST[$plugin->prefix.'_test_send_to_user'])) {
                      $res = $plugin->subclass->mail($options, $arr['to'], $arr['subject'], $arr['message']);
                      if (!$res) {
                        $error = $plugin->subclass::class() . "->mail() function failed";
                        $has_error = true;
                      }
                    }
                  }
                  else {
                    // sometimes there might be no digest to send (e.g. because we are on the test site or because
                    // not enough time has passed) but we still want to send a test mail.
                    $res = $plugin->subclass->mail($options, $arr['to'], "test", "test");
                    if (!$res) {
                      $error = get_class($plugin->subclass) . "->mail() function failed";
                      $has_error = true;
                    }
                  }
                }
              }
              if ($has_error) {
                die( $error);
              }
              return;
            }

	        	// post_types
	        	if ($plugin->tab_active == 'post') {
		        	if (isset($_POST[$plugin->prefix.'_post_types']) && !empty($_POST[$plugin->prefix.'_post_types'])) {
		        		$arr = array();
		        		$_POST[$plugin->prefix.'_post_types'] = $plugin->subclass->make_array($_POST[$plugin->prefix.'_post_types']);
		        		foreach ($_POST[$plugin->prefix.'_post_types'] as $post_type) {
		        			if (isset($_POST[$plugin->prefix.'_post_types_'.$post_type]) && !empty($_POST[$plugin->prefix.'_post_types_'.$post_type])) {
		        				$arr[$post_type] = $_POST[$plugin->prefix.'_post_types_'.$post_type];
		        				unset($_POST[$plugin->prefix.'_post_types_'.$post_type]);
		        			}
		        		}
		        		$_POST[$plugin->prefix.'_post_types'] = $arr;
		        	}
	        	}

	        	// process textarea fields
	        	$arr = array(
					'mail_message_header',
					'mail_message_excerpt',
					'mail_message_footer',
					'buddypress_reminder_message',
	        	);
	        	foreach ($arr as $key => $value) {
		        	if (isset($_POST[$plugin->prefix.'_'.$value]) && !empty($_POST[$plugin->prefix.'_'.$value])) {
		        		$_POST[$plugin->prefix.'_'.$value] = trim(stripslashes($_POST[$plugin->prefix.'_'.$value]));
		        	}
	        	}

				// get values
				$options_arr = $plugin->subclass->get_options_array($plugin->tab_active);
				if ($plugin->tab_active == 'post') {
					$options = $plugin->subclass->get_blog_option();
				}
				else {
					$options = $plugin->subclass->get_option();
				}
				foreach ($options_arr as $value) {
					$name = $plugin->prefix.'_'.$value;
					if (!isset($_POST[$name])) {
						if (array_key_exists($value, $options)) {
							unset($options[$value]);
						}
						continue;
					}
					if (empty($_POST[$name])) {
						if (array_key_exists($value, $options)) {
							unset($options[$value]);
						}
						continue;
					}
					$options[$value] = $_POST[$name];
				}
				// save it
	            if ($plugin->tab_active == 'post' && $plugin->subclass->update_blog_options($options)) {
	            	echo $updated;
	            }
	            elseif ($plugin->tab_active != 'post' && $plugin->subclass->update_options($options)) {
	            	echo $updated;
	            }
	        	else {
            		echo $error;
	        	}

				// maybe cron changed
				$plugin->subclass->cron_toggle();
			};
			$save();
        }

		// show the form
		$options_arr = $plugin->subclass->get_options_array($plugin->tab_active);
		if ($plugin->tab_active == 'post') {
			$options = $plugin->subclass->get_blog_option();
		}
		else {
			$options = $plugin->subclass->get_option();
		}
		$options = array_merge( array_fill_keys($options_arr, null), (array)$options );

		// tabs
		if ($plugin->tabs_all) {
		    $tabs = array(
		        'general' => __('General Settings'),
		        'post' => __('Post Settings'),
		        'mail' => __('Mail Settings'),
		    );
		    if ($plugin->subclass::$has_buddypress) {
		        $tabs['buddypress'] = __('Buddypress');
		    }
		    if ($plugin->subclass::$has_bbpress) {
		        $tabs['bbpress'] = __('Bbpress');
		    }
	    }
		else {
			echo '<p>'.__('Note: More settings are available in the Network Admin.').'</p>';
		    $tabs = array(
		        'post' => __('Post Settings'),
		    );
		}
	    $tabs['test'] = __('Send Test');
		?>
	    <h2 class="nav-tab-wrapper"><?php
	    	global $pagenow;
	        foreach ($tabs as $key => $value) {
				if (is_multisite() && is_network_admin()) {
	            	echo '<a class="nav-tab '.($plugin->tab_active == $key ? 'nav-tab-active' : '').'" href="'.esc_url( network_admin_url($pagenow.'?page='.$plugin->prefix.'&tab='.$key) ).'">'.$value.'</a> ';
				}
				else {
	            	echo '<a class="nav-tab '.($plugin->tab_active == $key ? 'nav-tab-active' : '').'" href="'.esc_url( admin_url($pagenow.'?page='.$plugin->prefix.'&tab='.$key) ).'">'.$value.'</a> ';
	            }
	        }
	    ?></h2>

	    <form id="<?php echo $plugin->prefix; ?>-admin-form" name="<?php echo $plugin->prefix; ?>-admin-form" method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
		<?php
		// Use nonce for verification
		wp_nonce_field(plugin_basename(__FILE__), $plugin->plugin_name.'::'.__FUNCTION__);
		?>
	    <div id="poststuff">

		<?php if ($plugin->tab_active == 'general') : ?>
        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('General Settings'); ?></h4>

		        <p><label for="<?php echo $plugin->prefix; ?>_active"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_active" name="<?php echo $plugin->prefix; ?>_active" value="1"<?php checked($options['active'], 1); ?> /> <?php echo $plugin->plugin_title; ?> active?</label></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_cron"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_cron" name="<?php echo $plugin->prefix; ?>_cron" value="1"<?php checked($options['cron'], 1); ?> /> <?php _e('Activate Cronjob?'); ?></label><br />
	            <span class="description"><?php _e('This option activates the hourly Cronjob that sends the mails.'); ?></span></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_cron_direct"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_cron_direct" name="<?php echo $plugin->prefix; ?>_cron_direct" value="1"<?php checked($options['cron_direct'], 1); ?> /> <?php _e('Execute the Cronjob via direct file.'); ?></label><br />
	            <span class="description"><?php _e('This can be faster, but may have problems with security plugins.'); ?></span></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_admin_email" style="display: inline-block; width: 16em;"><?php _e('Admin Email'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_admin_email" name="<?php echo $plugin->prefix; ?>_admin_email" value="<?php echo esc_attr($options['admin_email']); ?>" style="width: 50%;" /><br />
	            <span class="description"><?php _e('To send tests, errors and notices. Can be a comma-separated list.'); ?></span></p>
        	</div>
        </div>

        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('User Settings'); ?></h4>

	            <p><label for="<?php echo $plugin->prefix; ?>_opt_in"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_opt_in" name="<?php echo $plugin->prefix; ?>_opt_in" value="1"<?php checked($options['opt_in'], 1); ?> /> <?php _e('"Opt in" enabled.'); ?></label><br />
	            <span class="description"><?php _e('Only users who "opt in" will receive mails. When this is disabled all users will be mailed using the "Default Mailing Interval" below.'); ?></span></p>

				<p><label for="<?php echo $plugin->prefix; ?>_default_interval" style="display: inline-block; width: 16em;"><?php _e('Default Mailing Interval'); ?></label>
				<select id="<?php echo $plugin->prefix; ?>_default_interval" name="<?php echo $plugin->prefix; ?>_default_interval">
				<?php foreach ($plugin->subclass::$default_intervals as $key => $value) : ?>
					<option value="<?php echo esc_attr($key); ?>"<?php selected($key, $options['default_interval']); ?>><?php echo esc_html($value); ?></option>
				<?php endforeach; ?>
	            </select></p>

	            <p><strong><?php _e('Hidden Roles'); ?></strong><br />
	            <span class="description"><?php _e('Users with the following roles will be excluded from all mails.'); ?></span></p>
	            <?php
				global $wp_roles;
				if (!isset($wp_roles)) {
					$wp_roles = new WP_Roles();
				}
				$options['hidden_roles'] = $plugin->subclass->make_array($options['hidden_roles']);
				foreach ($wp_roles->role_names as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin->prefix.'_hidden_roles[]" value="'.$key.'"';
					if (in_array($key, $options['hidden_roles'])) {
						checked($key, $key);
					}
					echo '> '.$value.'</label>';
	            }
	            ?>
        	</div>
        </div>

		<?php elseif ($plugin->tab_active == 'post') : ?>
        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Post Settings'); ?></h4>

	            <p><strong><?php _e('Post Types'); ?></strong><br />
	            <span class="description"><?php _e('Users will only be mailed with the following post types.'); ?><br />
	            <?php _e('Post Created: Mail when the post is created.'); ?><br />
	            <?php _e('Post Modified: Mail when the post is modified. Careful, this means the same post can be mailed more than once.'); ?></span></p>
	            <?php
	            $post_types = array();
	            $arr = get_post_types(array('public' => true), 'objects');
	            foreach ($arr as $key => $value) {
	            	$post_types[$key] = $value->label;
	            }
	            $post_types = apply_filters('posttoemail_post_types', $post_types);
	            $options['post_types'] = $plugin->subclass->make_array($options['post_types']);

	            // select function
	            $func = function($post_type) use ($plugin, $options) {
	            	$arr = array(
	            		'post_date' => __('Post Created'),
	            		'post_modified' => __('Post Modified'),
	            	);
	            	$val = null;
	            	if (isset($options['post_types'][$post_type]) && !empty($options['post_types'][$post_type])) {
	            		$val = $options['post_types'][$post_type];
	            	}
	            	$str = '<select id="'.$plugin->prefix.'_post_types_'.$post_type.'" name="'.$plugin->prefix.'_post_types_'.$post_type.'"'.disabled(null, $val, false).'>';
	            	foreach ($arr as $key => $value) {
	            		$str .= '<option value="'.esc_attr($key).'"'.selected($key, $val, false).'>'.esc_html($value).'</option>';
	            	}
	            	$str .= '</select>';
	            	return $str;
	            };

	            foreach ($post_types as $key => $value) {
					echo '<label style="display: inline-block; width: 50%;"><span style="display: inline-block; width: 16em;"><input type="checkbox" name="'.$plugin->prefix.'_post_types[]" value="'.$key.'"';
					if (array_key_exists($key, $options['post_types'])) {
						checked($key, $key);
					}
					echo '> '.$value.'</span>'.$func($key).'</label>';
	            }
	            ?>
				<script type="text/javascript">
				jQuery(document).ready(function($) {
					$('input[name="<?php echo $plugin->prefix; ?>_post_types[]"]').on("change",function(){
						var select = $('select#<?php echo $plugin->prefix; ?>_post_types_'+this.value);
						if (!select.length) {
							return;
						}
						if (this.checked === true) {
							select.attr('disabled', false);
						}
						else {
							select.attr('disabled', true);
						}
					});
				});
				</script>

	            <p><strong><?php _e('Excluded Posts'); ?></strong><br />
	            <span class="description"><?php _e('Mails will be excluded from the following posts and any child posts.'); ?></span></p>
	            <?php
	            if (empty($options['post_types'])) {
	            	echo '<p>';
	            	_e('No Post Types selected.');
	            	echo '</p>';
	            }
	        	else {
					$options['excluded_posts'] = $plugin->subclass->make_array($options['excluded_posts']);
		            $options['excluded_posts'] = apply_filters('posttoemail_excluded_posts', $options['excluded_posts']);

	        		foreach ($options['post_types'] as $key => $value) {
		            	echo '<p style="margin-bottom: 0.5em;">'.$post_types[$key].'</p>';
		            	// TODO: catch Post categories
						$posts = get_posts(array(
							'no_found_rows' => true,
							'nopaging' => true,
							'ignore_sticky_posts' => true,
							'post_status' => 'publish,inherit',
							'post_type' => $key,
							'orderby' => 'menu_order',
							'order' => 'ASC',
							'post_parent' => 0,
							'suppress_filters' => false,
				        ));
			            if (empty($posts)) {
			            	echo '<p style="margin-top: 0;">';
			            	_e('No top level posts found.');
			            	echo '</p>';
			            	continue;
			            }
						foreach ($posts as $post) {
							echo '<label style="display: inline-block; width: 50%;"><input type="checkbox" name="'.$plugin->prefix.'_excluded_posts[]" value="'.$post->ID.'"';
							if (in_array($post->ID, $options['excluded_posts'])) {
								checked($post->ID, $post->ID);
							}
							echo '> '.get_the_title($post).'</label>';
						}
	        		}
	        	}
	            ?>
        	</div>
        </div>

		<?php elseif ($plugin->tab_active == 'mail') : ?>
        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Mail Settings'); ?></h4>

				<?php $plugin->admin_legend(); ?>

	            <p><label for="<?php echo $plugin->prefix; ?>_mail_from" style="display: inline-block; width: 16em;"><?php _e('From'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_mail_from" name="<?php echo $plugin->prefix; ?>_mail_from" value="<?php echo esc_attr($options['mail_from']); ?>" style="width: 50%;" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_mail_replyto" style="display: inline-block; width: 16em;"><?php _e('Reply-To'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_mail_replyto" name="<?php echo $plugin->prefix; ?>_mail_replyto" value="<?php echo esc_attr($options['mail_replyto']); ?>" style="width: 50%;" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_mail_subject" style="display: inline-block; width: 16em;"><?php _e('Subject'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_mail_subject" name="<?php echo $plugin->prefix; ?>_mail_subject" value="<?php echo esc_attr($options['mail_subject']); ?>" style="width: 50%;" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_mail_stylesheet" style="display: inline-block; width: 16em;"><?php _e('Stylesheet URL'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_mail_stylesheet" name="<?php echo $plugin->prefix; ?>_mail_stylesheet" value="<?php echo esc_attr($options['mail_stylesheet']); ?>" style="width: 50%;" />
                <button type="submit" class="wp_media_button_upload button"><?php _e('Select/Upload'); ?></button>
                <button type="submit" class="wp_media_button_remove button">&times;</button><br />
	            <span class="description"><?php _e('You may need to allow CSS files in the "Upload file types" setting.'); ?></span></p>

		        <p><label for="<?php echo $plugin->prefix; ?>_disable_richedit"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_disable_richedit" name="<?php echo $plugin->prefix; ?>_disable_richedit" value="1"<?php checked($options['disable_richedit'], 1); ?> /> <?php _e('Disable Richtext Editor?'); ?></label></p>

	            <?php
				$plugin->admin_textarea($options, 'mail_message_header', __('Message Header'));

				$plugin->admin_textarea($options, 'mail_message_excerpt', __('Message Excerpt'));
				?>

	            <p><label for="<?php echo $plugin->prefix; ?>_mail_message_excerpt_length" style="display: inline-block; width: 16em;"><?php _e('Excerpt character length'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_mail_message_excerpt_length" name="<?php echo $plugin->prefix; ?>_mail_message_excerpt_length" value="<?php echo esc_attr($options['mail_message_excerpt_length']); ?>" style="width: 8em;" /></p>

	            <?php $plugin->admin_textarea($options, 'mail_message_footer', __('Message Footer')); ?>
        	</div>
        </div>

		<?php elseif ($plugin->tab_active == 'buddypress') : ?>
        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Buddypress Settings'); ?></h4>

	            <?php $plugin->admin_legend(); ?>

				<p><span class="description"><?php _e('This function can remind users to update their profile fields.'); ?></span></p>

		        <p><label for="<?php echo $plugin->prefix; ?>_buddypress_reminder_active"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_buddypress_reminder_active" name="<?php echo $plugin->prefix; ?>_buddypress_reminder_active" value="1"<?php checked($options['buddypress_reminder_active'], 1); ?> /> <?php _e('Reminder active?'); ?></label></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_buddypress_reminder_interval" style="display: inline-block; width: 16em;"><?php _e('Reminder Interval'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_buddypress_reminder_interval" name="<?php echo $plugin->prefix; ?>_buddypress_reminder_interval" value="<?php echo esc_attr($options['buddypress_reminder_interval']); ?>" style="width: 8em;" /></p>

	            <p><label for="<?php echo $plugin->prefix; ?>_buddypress_reminder_fields_age" style="display: inline-block; width: 16em;"><?php _e('Minimum age of profile fields'); ?></label>
	            <input type="text" id="<?php echo $plugin->prefix; ?>_buddypress_reminder_fields_age" name="<?php echo $plugin->prefix; ?>_buddypress_reminder_fields_age" value="<?php echo esc_attr($options['buddypress_reminder_fields_age']); ?>" style="width: 8em;" /></p>

	            <?php $plugin->admin_textarea($options, 'buddypress_reminder_message', __('Reminder Message'), __('Should be inserted in "Mail Settings".')); ?>
        	</div>
        </div>

		<?php elseif ($plugin->tab_active == 'bbpress') : ?>
        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Bbpress Settings'); ?></h4>

		        <p><label for="<?php echo $plugin->prefix; ?>_bbpress_use_subs"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_bbpress_use_subs" name="<?php echo $plugin->prefix; ?>_bbpress_use_subs" value="1"<?php checked($options['bbpress_use_subs'], 1); ?> /> <?php _e('Only mail forums/topics/replies when the user is subscribed.'); ?></label></p>
        	</div>
        </div>

		<?php elseif ($plugin->tab_active == 'test') : ?>
        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Test User'); ?></h4>

	            <?php
				$test_user_id = (isset($_POST[$plugin->prefix.'_test_user_id']) ? $_POST[$plugin->prefix.'_test_user_id'] : null);
		        $users = $plugin->subclass->get_users();
	            ?>
	            <p><label for="<?php echo $plugin->prefix; ?>_test_user_id" style="display: inline-block; width: 16em;"><?php _e('Selected User'); ?></label>
	            <select id="<?php echo $plugin->prefix; ?>_test_user_id" name="<?php echo $plugin->prefix; ?>_test_user_id">
	            	<option value="">--</option>
            	<?php foreach ($users as $userdata) :
            		if ($test_user_id == $userdata->ID) {
            			$test_userdata = $userdata;
            		}
            	?>
					<option value="<?php echo esc_attr($userdata->ID); ?>"<?php selected($userdata->ID, $test_user_id); ?>><?php echo esc_html($userdata->display_name.' <'.$userdata->user_email.'>'); ?></option>
				<?php endforeach; ?>
	            </select></p>

				<?php if (!empty($test_user_id)) : ?>
					<?php
					$usermeta = $plugin->subclass->get_cache_wp_usermeta_extended($test_user_id);

					$usermeta['interval'] = (!empty($usermeta['interval']) ? $plugin->subclass::$default_intervals[$usermeta['interval']] : '--');
					$usermeta['last_sent'] = (!empty($usermeta['last_sent']) ? $usermeta['last_sent'] : '--');
					?>
					<p><label style="display: inline-block; width: 16em;"><?php _e('Mail Interval'); ?></label>
					<?php echo $usermeta['interval']; ?></p>

					<p><label style="display: inline-block; width: 16em;"><?php _e('Mail Last Sent'); ?></label>
					<?php echo $usermeta['last_sent']; ?></p>

					<?php if ($plugin->subclass::$has_buddypress) : 
						$usermeta['last_sent_buddypress_reminder'] = (!empty($usermeta['last_sent_buddypress_reminder']) ? $usermeta['last_sent_buddypress_reminder'] : '--');
					?>
					<p><label style="display: inline-block; width: 16em;"><?php _e('Buddypress Reminder Last Sent'); ?></label>
					<?php echo $usermeta['last_sent_buddypress_reminder']; ?></p>
				<?php endif; ?>

		    	<?php endif; ?>
        	</div>
        </div>
		<?php if (!empty($test_user_id)) : ?>
        <div class="postbox">
        	<div class="inside">
	            <h4><?php _e('Test Mail'); ?></h4>

				<?php
				// default css
				$arr = array(dirname(__DIR__)."/assets/css/editor-style.css");
				// custom css
				if (!empty($options['mail_stylesheet']) && strpos($options['mail_stylesheet'], '.css') !== false) {
					$arr[] = $options['mail_stylesheet'];
				}
				$css = '<style type="text/css">';
				foreach ($arr as $value) {
					if ($str = $plugin->subclass->get_file_contents($value)) {
						$css .= $str;
					}
				}
				$css .= '</style>';
				$replace = array(
					"\n" => '',
					"\t" => '',
				);
				$css = str_replace(array_keys($replace), $replace, $css);
				$plugin->subclass->current_css = $plugin->subclass->trim_excess_space($css)."\n";

				if ($arr = $plugin->subclass->get_message_array($options, $test_userdata)) : ?>
					<p><label style="display: inline-block; width: 16em;"><?php _e('From'); ?></label>
					<?php echo esc_html($options['mail_from']); ?></p>
					<p><label style="display: inline-block; width: 16em;"><?php _e('Reply-To'); ?></label>
					<?php echo esc_html($options['mail_replyto']); ?></p>
					<p><label style="display: inline-block; width: 16em;"><?php _e('To'); ?></label>
					<?php echo esc_html($arr['to']); ?></p>
					<p><label style="display: inline-block; width: 16em;"><?php _e('Subject'); ?></label>
					<?php echo esc_html($arr['subject']); ?></p>
					<hr />
					<iframe srcdoc="<?php echo esc_attr($arr['message']); ?>" style="width: 100%; height: 30em;"></iframe>
				<?php else : ?>
					<p><?php _e('No mail.'); ?></p>
				<?php endif; ?>
        	</div>
        </div>
        <div class="postbox">
        	<div class="inside">
				<p><label for="<?php echo $plugin->prefix; ?>_test_send_to_admin"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_test_send_to_admin" name="<?php echo $plugin->prefix; ?>_test_send_to_admin" value="1" /> <?php _e('Send test to Admin Email?'); ?></label></p>
				<p><label for="<?php echo $plugin->prefix; ?>_test_send_to_user"><input type="checkbox" id="<?php echo $plugin->prefix; ?>_test_send_to_user" name="<?php echo $plugin->prefix; ?>_test_send_to_user" value="1" /> <?php _e('Send test to User Email?'); ?></label></p>
        	</div>
        </div>
    	<?php endif; ?>

    	<?php endif; ?>

		<?php submit_button(null, 'primary', 'save'); ?>

        </div><!-- poststuff -->
    	</form>

		</div><!-- wrap -->
		<?php
	}

	/* actions + filters */

	public function cron_action() {
		$active = $this->subclass->get_option('active', false);
		if (empty($active)) {
			return;
		}
		$cron = $this->subclass->get_option('cron', false);
		if (empty($cron)) {
			$this->subclass->cron_toggle(false);
			return;
		}
		$res = null;
		$cron_direct = $this->subclass->get_option('cron_direct', false);
		// execute in the action
		if (empty($cron_direct)) {
			if (!class_exists('Post_To_Email_Cron')) {
				@include_once(dirname(__FILE__).'/class-post-to-email-cron.php');
			}
			if (class_exists('Post_To_Email_Cron')) {
				$res = new Post_To_Email_Cron();
			}
		}
		// execute by calling the file
		else {
			$url = plugin_dir_url(__FILE__).'class-post-to-email-cron.php';
			if (function_exists('curl_init')) {
                $c = @curl_init();
                // try 'correct' way
                curl_setopt($c, CURLOPT_URL, $url);
                curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($c, CURLOPT_MAXREDIRS, 10);
                $res = curl_exec($c);
                // try 'insecure' way
                if (empty($res)) {
                    curl_setopt($c, CURLOPT_URL, $url);
                    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
					$user_agent = $this->plugin_title;
					if (isset($_SERVER["HTTP_USER_AGENT"]) && !empty($_SERVER["HTTP_USER_AGENT"])) {
						$user_agent = $_SERVER["HTTP_USER_AGENT"];
					}
                    curl_setopt($c, CURLOPT_USERAGENT, $user_agent);
                    $res = curl_exec($c);
                }
                curl_close($c);
			}
			if (empty($res)) {
				$cmd = 'wget -v '.$url.' >/dev/null 2>&1';
				@exec($cmd, $res);
			}
		}
	}

	public function edit_user_profile($profileuser = null) {
		if (!is_object($profileuser)) {
			return;
		}
		if (in_array($profileuser->ID, $this->subclass::$exclude_users)) {
			return;
		}
		if ($this->subclass->user_has_hidden_role($profileuser->roles)) {
			return;
		}
		$has_cap = false;
		global $current_user;
		if ($current_user->ID == $profileuser->ID) {
			$has_cap = true;
		}
		elseif (current_user_can('edit_users')) {
			$has_cap = true;
		}
		elseif (is_multisite() && current_user_can('manage_network_users')) {
			$has_cap = true;
		}
		if (!$has_cap) {
			return;
		}
		$usermeta = $this->subclass->get_cache_wp_usermeta_extended($profileuser->ID);
		?>
<h2><?php echo $this->plugin_title; ?></h2>
<table class="form-table">
<?php
foreach ($usermeta as $key => $value) : 
$label = ucwords(str_replace("_", ' ', $key));
?>
<tr>
	<th><label for="<?php echo $this->prefix; ?>_<?php echo $key; ?>"><?php echo $label; ?></label></th>
	<td><?php switch ($key) {
		case 'interval': ?>
			<select id="<?php echo $this->prefix; ?>_<?php echo $key; ?>" name="<?php echo $this->prefix; ?>_<?php echo $key; ?>">
				<option value="">--</option>
			<?php foreach ($this->subclass::$default_intervals as $interval_key => $interval_value) : ?>
				<option value="<?php echo esc_attr($interval_key); ?>"<?php selected($interval_key, $value); ?>><?php echo esc_html($interval_value); ?></option>
			<?php endforeach; ?>
			</select><?php
			break;
		default: ?>
			<input type="text" name="<?php echo $this->prefix; ?>_<?php echo $key; ?>" id="<?php echo $this->prefix; ?>_<?php echo $key; ?>" class="regular-text" value="<?php echo esc_attr($value); ?>" autocomplete="off" /><?php
			break;
	}?></td>
</tr>
<?php
endforeach;
?>
</table>
		<?php
	}

	public function edit_user_profile_update($user_id = 0) {
		if (empty($user_id)) {
			return;
		}
		if (in_array($user_id, $this->subclass::$exclude_users)) {
			return;
		}
		$userdata = get_userdata($user_id);
		if ($this->subclass->user_has_hidden_role($userdata->roles)) {
			return;
		}
		$has_cap = false;
		global $current_user;
		if ($current_user->ID == $profileuser->ID) {
			$has_cap = true;
		}
		elseif (current_user_can('edit_users')) {
			$has_cap = true;
		}
		elseif (is_multisite() && current_user_can('manage_network_users')) {
			$has_cap = true;
		}
		if (!$has_cap) {
			return;
		}
		$usermeta_arr = $this->subclass->get_usermeta_array();
		$usermeta = array();
		foreach ($usermeta_arr as $value) {
			$name = $this->prefix.'_'.$value;
			if (!isset($_POST[$name])) {
				continue;
			}
			if (empty($_POST[$name])) {
				continue;
			}
			$usermeta[$value] = stripslashes($_POST[$name]);
		}
		if (!empty($usermeta)) {
			update_user_meta($user_id, $this->prefix, $usermeta);
		}
		else {
			delete_user_meta($user_id, $this->prefix);
		}
	}

	public function deleted_user($user_id = 0) {
		if (empty($user_id)) {
			return;
		}
		delete_user_meta($user_id, $this->prefix);
	}

	public function bp_notification_settings() {
		global $current_user;
		// save
		if ($_POST['submit'] && isset($_POST[$this->prefix.'_interval'])) {
			$this->subclass->update_user_meta($current_user->ID, 'interval', $_POST[$this->prefix.'_interval']);
		}
		// form
		$arr = $this->subclass->get_cache_wp_usermeta_extended($current_user->ID);
		$str = $arr['interval'];
		if (empty($str)) {
			$str = $this->subclass->get_option('default_interval', 'weekly');
		}
		?>
		<div class="<?php echo $this->prefix; ?>">
		<h2><?php _e('Email Digest'); ?></h2>
		<p><?php _e('Email me a list of new and updated posts:'); ?>
		<select id="<?php echo $this->prefix; ?>_interval" name="<?php echo $this->prefix; ?>_interval">
		<?php foreach ($this->subclass::$default_intervals as $key => $value) : ?>
			<option value="<?php echo esc_attr($key); ?>"<?php selected($key, $str); ?>><?php echo esc_html($value); ?></option>
		<?php endforeach; ?>
		</select></p>
		</div>
		<?php
	}

	/* functions - admin */

	private function admin_textarea($options, $field, $title = '', $description = '') {
		if (!empty($description)) {
			$description = '<br /><span class="description">'.$description.'</span>';
		}
		if (user_can_richedit() && empty($options['disable_richedit'])) {
			if (!isset($this->editor_styles)) {
				global $editor_styles;
				// remove other styles
				$editor_styles = array();
				// default css
				$arr = array(plugin_dir_url(dirname(__FILE__))."assets/css/editor-style.css");
				// custom css
				if (!empty($options['mail_stylesheet']) && strpos($options['mail_stylesheet'], '.css') !== false) {
					$arr[] = $options['mail_stylesheet'];
				}
				add_editor_style($arr);
				$this->editor_styles = $arr;
			}
			$editor_args = array(
				'textarea_rows' => 5,
				'wpautop' => true,
			);
			?>
			<p style="margin-bottom: 0.5em;"><label for="<?php echo $this->prefix; ?>_<?php echo $field; ?>"><?php echo $title; ?></label><?php echo $description; ?></p>
			<?php
			wp_editor($options[$field], $this->prefix.'_'.$field, $editor_args);
		}
		else {
			?>
			 <p><label for="<?php echo $this->prefix; ?>_<?php echo $field; ?>" style="display: inline-block; width: 16em; vertical-align: top;"><?php echo $title; ?></label>
			 <textarea id="<?php echo $this->prefix; ?>_<?php echo $field; ?>" name="<?php echo $this->prefix; ?>_<?php echo $field; ?>" style="width: 50%;" rows="5"><?php echo htmlspecialchars($options[$field], ENT_QUOTES); ?></textarea><?php echo $description; ?></p>
			 <?php
		}
		return;
	}

	private function admin_legend() {
		global $current_user;
		?>
		<div style="position: fixed; right: 3em; z-index: 9;">
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('.admin_legend_button').click(function() {
				var div = $(this).parent().find('div.admin_legend');
				if (!div.length) {
					return false;
				}
				div.slideToggle('fast');
				return false;
			});
		});
		</script>
	    <button type="submit" class="admin_legend_button button" style=""><?php _e('Legend'); ?></button>
	    <div class="admin_legend postbox" style="display: none; position: fixed; right: 3em; max-width: 50%; max-height: 50%; overflow: auto;">
        	<div class="inside">
		    	<table>
		    		<tr>
		    			<td><?php echo $this->subclass::$hash; ?>DATE<?php echo $this->subclass::$hash; ?></td>
		    			<td><span class="description"><?php echo date($this->subclass->get_cache_wp_options('date_format', 'd/m/Y'), $this->subclass::$time); ?></span></td>
		    		</tr>
		    		<tr>
		    			<td><?php echo $this->subclass::$hash; ?>BLOGNAME<?php echo $this->subclass::$hash; ?></td>
		    			<td><span class="description"><?php echo $this->subclass->get_cache_main_blog_options('blogname', ''); ?></span></td>
		    		</tr>
		    		<tr>
		    			<td><?php echo $this->subclass::$hash; ?>SITEURL<?php echo $this->subclass::$hash; ?></td>
		    			<td><span class="description"><?php echo $this->subclass->get_cache_main_blog_options('siteurl', ''); ?></span></td>
		    		</tr>
		    		<?php
		    		$arr = $this->subclass->get_wp_users_fields();
		    		foreach ($arr as $value) {
		    		?>
		    		<tr>
		    			<td><?php echo $this->subclass::$hash.strtoupper($value).$this->subclass::$hash; ?></td>
		    			<td><span class="description"><?php echo $current_user->$value; ?></span></td>
		    		</tr>
					<?php
					}
					?>
    		<?php if ($this->tab_active == 'mail' || $this->tab_active == 'test') : ?>
		    		<?php if ($this->subclass::$has_buddypress) : ?>
		    		<tr>
		    			<td><?php echo $this->subclass::$hash; ?>BUDDYPRESS_REMINDER<?php echo $this->subclass::$hash; ?></td>
		    			<td><span class="description"><?php echo $this->subclass->get_excerpt($this->subclass->get_option('buddypress_reminder_message', '')); ?></span></td>
		    		</tr>
		    		<?php endif; ?>
		    		<tr>
		    			<td><?php _e('Message Excerpt only (example):'); ?></td>
		    			<td></td>
		    		</tr>
		    		<?php
		    		$post_type = $this->subclass->get_blog_option('post_types', array('page' => 'post_date'));
		    		$post_type = key($post_type);
					$post = get_posts(array(
						'no_found_rows' => true,
						'nopaging' => true,
						'ignore_sticky_posts' => true,
						'post_status' => 'publish',
						'post_type' => $post_type,
						'numberposts' => 1,
						'orderby' => 'menu_order,post_title',
						'order' => 'ASC',
			        ));
			        if (!empty($post)) {
			        	$post = $this->subclass->get_cache_wp_posts_extended($post[0]->ID, $post[0], 'post_date', 'all');
			        }
			    	else {
			    		$post = array_fill_keys($this->subclass->get_wp_posts_extended_fields('all'), 'string');
			    	}
			    	foreach ($this->subclass->get_wp_posts_extended_fields('all') as $value) {
		    		?>
		    		<tr>
		    			<td><?php echo $this->subclass::$hash.strtoupper($value).$this->subclass::$hash; ?></td>
		    			<td><span class="description"><?php echo $post[$value]; ?></span></td>
		    		</tr>
					<?php
					}
					?>
			<?php endif; ?>
    		<?php if ($this->tab_active == 'buddypress') : ?>
		    		<tr>
		    			<td><?php _e('Buddypress Reminder only:'); ?></td>
		    			<td></td>
		    		</tr>
		    		<?php
		    		if ($arr = $this->subclass->get_buddypress_xprofile_fields('all')) {
		    			foreach ($arr as $key => $value) {
		    				if ($key % 2 == 0) {
		    					?><tr><?php
		    				}
		    				?><td><?php echo $this->subclass::$hash.$value.$this->subclass::$hash; ?></td><?php
		    				if ($key % 2 !== 0) {
		    					?></tr><?php
		    				}
		    			}
		    			if (count($arr) % 2 !== 0) {
		    				?><td></td></tr><?php
		    			}
		    		}
		    		?>
			<?php endif; ?>
		    	</table>
		    </div>
	    </div>
	    </div>
		<?php
	}

}
endif;
?>
