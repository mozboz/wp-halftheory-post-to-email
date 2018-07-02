<?php
/*
Available filters:
*/
set_time_limit(0);

// accessed directly?
if (!defined('ABSPATH')) {
	// load wp
	$wp_load = false;
	$root_paths = array(
		substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/wp-content')),
		substr(dirname($_SERVER['SCRIPT_FILENAME']), 0, strpos(dirname($_SERVER['SCRIPT_FILENAME']), '/wp-content')),
		$_SERVER['DOCUMENT_ROOT'],
	);
	foreach ($root_paths as $value) {
		if (is_file($value.'/wp-load.php')) {
			@include_once($value.'/wp-load.php');
			$wp_load = true;
			break;
		}
	}
	if (!$wp_load) {
		exit;
	}
	unset($wp_load);
	unset($root_paths);
	$cron_direct = true;
}

if (!class_exists('Post_To_Email_Cron')) :
class Post_To_Email_Cron {

	var $messages = array();

	public function __construct() {
		if (!class_exists('Post_To_Email')) {
			@include_once(dirname(__FILE__).'/class-post-to-email.php');
		}
		if (!class_exists('Post_To_Email')) {
			return;
		}
		$this->subclass = Post_To_Email::init();
		$this->plugin_name = $this->subclass::$plugin_name;
		$this->plugin_title = $this->subclass::$plugin_title;
		$this->prefix = $this->subclass::$prefix;

		$active = $this->subclass->get_option('active', false);
		if (empty($active)) {
			return;
		}
		$cron = $this->subclass->get_option('cron', false);
		if (empty($cron)) {
			$this->subclass->cron_toggle(false);
			return;
		}

		$this->cron();
		$this->admin_messages();
	}

	private function cron() {
		$options = $this->subclass->get_option();

		// which users?
		$scope = array();
		$scope_allowed = array('daily');
		// first day of week?
		global $wp_locale;
		$start_of_week = $wp_locale->get_weekday($this->subclass->get_cache_wp_options('start_of_week', 1));
		if ($start_of_week == date("l", $this->subclass::$time)) {
			$scope_allowed[] = 'weekly';
		}
		// first day of month?
		$date_of_month = date("j", $this->subclass::$time);
		if (1 == absint($date_of_month)) {
			$scope_allowed[] = 'monthly';
		}
		if (isset($options['opt_in']) && !empty($options['opt_in'])) {
			$scope = $scope_allowed;
		}
		elseif (isset($options['default_interval']) && !empty($options['default_interval'])) {
			if (in_array($options['default_interval'], $scope_allowed)) {
				$scope[] = $options['default_interval'];
			}
		}
		if (empty($scope)) {
			return;
		}

		$users = $this->subclass->get_users('user_email', $scope);
		foreach ($users as $userdata) {
			if ($arr = $this->subclass->get_message_array($options, $userdata)) {
				if ($this->subclass->mail($options, $arr['to'], $arr['subject'], $arr['message'])) {
					// $this->messages[] = __FUNCTION__.' - sent - '.$arr['to'];
				}
				else {
					$this->messages[] = __FUNCTION__.' - not sent - '.$arr['to'];
				}
			}
			else {
				// $this->messages[] = __FUNCTION__.' - no posts - '.$userdata->user_email;
			}
		}
		if (!empty($this->messages)) {
			$this->messages[] = __FUNCTION__.' - scope - '.implode(",", $scope);
		}
	}

	private function admin_messages() {
		if (empty($this->messages)) {
			echo 'OK!';
			return;
		}
		$this->messages = array_unique($this->messages);
		$message = implode("\n", $this->messages);
		echo $message;

		// mail
		$admin_email = $this->subclass->get_option('admin_email', '');
		if (empty($admin_email)) {
			return;
		}
		wp_mail($admin_email, $this->subclass->plugin_title.' plugin notice - '.get_called_class(), $message);
	}

}
endif;

if (isset($cron_direct)) {
	$cron = new Post_To_Email_Cron();
	exit;
}
?>