<?php
/*
Available filters:
posttoemail_exclude_users
posttoemail_excluded_posts
posttoemail_default_author
posttoemail_get_excerpt_default_args
posttoemail_get_blogs_of_user
posttoemail_message_array
*/

// Exit if accessed directly.
defined('ABSPATH') || exit;

function post_to_email_log($message) {
  
  file_put_contents('/home/gca/web/wp-content/plugins/wp-halftheory-post-to-email/test.log', date("Y-m-d H:i:s") . "  $message\n", FILE_APPEND);
}

if (!class_exists('Post_To_Email')) :
class Post_To_Email {

  public static $instance;
  public static $plugin_name;
  public static $plugin_title;
  public static $prefix;
  public static $time;
  public static $date_format;
  public static $default_intervals = array();
  private static $cache = array();
  public static $exclude_users = array();
  public static $has_buddypress = false;
  public static $has_bbpress = false;
  public static $hash = '###';
  public static $is_test = false;

  private function __construct() {
  }

  public static function init() {
    if (empty(self::$instance)) {
      $instance = new self;
      $instance->setup_globals();
      self::$instance = $instance;
    }
    return self::$instance;
  }

  private function setup_globals() {
    self::$plugin_name = get_called_class();
    self::$plugin_title = ucwords(str_replace('_', ' ', self::$plugin_name));
    self::$prefix = sanitize_key(self::$plugin_name);
    self::$prefix = preg_replace("/[^a-z0-9]/", "", self::$prefix);

    self::$time = time();
    self::$date_format = 'Y-m-d H:i:s';

    self::$default_intervals = array(
      'daily' => __('Daily'),
      'weekly' => __('Weekly'),
      'monthly' => __('Monthly'),
      'never' => __('Never'),
    );

    self::$cache = array(
      'plugin_options' => array(),
      'wp_options' => array(),
      'wp_posts_extended' => array(),
      'wp_usermeta_extended' => array(),
    );

    self::$exclude_users = apply_filters('posttoemail_exclude_users', self::$exclude_users);

    // buddypress
    if (function_exists('buddypress') && function_exists('bp_is_active')) {
      if (bp_is_active('xprofile')) {
        self::$has_buddypress = true;
      }
    }
    // bbpress
    if (function_exists('bbpress')) {
      self::$has_bbpress = true;
      $this->bbpress_post_types = array(
        bbp_get_forum_post_type(),
        bbp_get_topic_post_type(),
        bbp_get_reply_post_type()
      );
    }
  }

  /* functions - common */

  public function make_array($str = '', $sep = ',') {
    if (function_exists(__FUNCTION__)) {
      $func = __FUNCTION__;
      return $func($str, $sep);
    }
    if (is_array($str)) {
      return $str;
    }
    if (empty($str)) {
      return array();
    }
    $arr = explode($sep, $str);
    $arr = array_map('trim', $arr);
    $arr = array_filter($arr);
    return $arr;
  }

  public function is_front_end() {
    if (function_exists(__FUNCTION__)) {
      $func = __FUNCTION__;
      return $func();
    }
    if (is_admin() && !wp_doing_ajax()) {
      return false;
    }
    if (wp_doing_ajax()) {
      if (strpos($this->get_current_uri(), admin_url()) !== false) {
        return false;
      }
    }
    return true;
  }

  public function get_current_uri() {
    if (function_exists(__FUNCTION__)) {
      $func = __FUNCTION__;
      return $func();
    }
    $res  = is_ssl() ? 'https://' : 'http://';
    $res .= $_SERVER['HTTP_HOST'];
    $res .= $_SERVER['REQUEST_URI'];
    if (wp_doing_ajax()) {
      if (!empty($_SERVER["HTTP_REFERER"])) {
        $res = $_SERVER["HTTP_REFERER"];
      }
    }
    return $res;
  }

  private function get_main_blog_id() {
    if (function_exists(__FUNCTION__)) {
      $func = __FUNCTION__;
      return $func();
    }
    return get_network()->blog_id;
  }

  private function get_the_author_by_post_id($post_id = 0) {
    if (function_exists(__FUNCTION__)) {
      $func = __FUNCTION__;
      return $func($post_id);
    }
    $str = '';
    $post = get_post($post_id);
    if (!empty($post)) {
        $str = get_the_author_meta('display_name', $post->post_author);
      $str = apply_filters('the_author', $str);
    }
    if (empty($str)) {
      $str = apply_filters('posttoemail_default_author', get_bloginfo('name'));
    }
    return $str;
  }
  private function get_the_modified_author_by_post_id($post_id = 0) {
    if (function_exists(__FUNCTION__)) {
      $func = __FUNCTION__;
      return $func($post_id);
    }
    $str = '';
    if ($last_id = get_post_meta($post_id, '_edit_last', true)) {
      if ($last_user = get_userdata($last_id)) {
        $str = apply_filters('the_modified_author', $last_user->display_name);
      }
    }
    if (empty($str)) {
      $str = $this->get_the_author_by_post_id($post_id);
    }
    return $str;
  }

  public function get_file_contents($url = '') {
    if (function_exists(__FUNCTION__)) {
      $func = __FUNCTION__;
      return $func($url);
    }
    if (empty($url)) {
      return false;
    }
    $str = '';
    // use user_agent when available
    $user_agent = self::$plugin_title;
    if (isset($_SERVER["HTTP_USER_AGENT"]) && !empty($_SERVER["HTTP_USER_AGENT"])) {
      $user_agent = $_SERVER["HTTP_USER_AGENT"];
    }
    // try php
    $options = array('http' => array('user_agent' => $user_agent));
    // try 'correct' way
    if ($str_php = @file_get_contents($url, false, stream_context_create($options))) {
      $str = $str_php;
    }
    // try 'insecure' way
    if (empty($str)) {
      $options['ssl'] = array(
        'verify_peer' => false,
        'verify_peer_name' => false,
      );
      if ($str_php = @file_get_contents($url, false, stream_context_create($options))) {
        $str = $str_php;
      }
    }
    // try curl
    if (empty($str)) {
      if (function_exists('curl_init')) {
        $c = @curl_init();
        // try 'correct' way
        curl_setopt($c, CURLOPT_URL, $url);
                curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($c, CURLOPT_MAXREDIRS, 10);
                $str = curl_exec($c);
                // try 'insecure' way
                if (empty($str)) {
                    curl_setopt($c, CURLOPT_URL, $url);
                    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
                    curl_setopt($c, CURLOPT_USERAGENT, $user_agent);
                    $str = curl_exec($c);
                }
        curl_close($c);
      }
    }
    if (empty($str)) {
      return false;
    }
    return $str;
  }

  private function strip_all_shortcodes($str = '') {
    if (function_exists(__FUNCTION__)) {
      $func = __FUNCTION__;
      return $func($str);
    }
    if (function_exists('strip_shortcodes')) {
      $str = strip_shortcodes($str);
    }
    return $str;
  }

  public function trim_excess_space($str = '') {
    if (function_exists(__FUNCTION__)) {
      $func = __FUNCTION__;
      return $func($str);
    }
    if (empty($str)) {
      return $str;
    }
    $str = str_replace("&nbsp;", ' ', $str);
    $str = str_replace("&#160;", ' ', $str);
    $str = str_replace("\xc2\xa0", ' ',$str);
    if (strpos($str, "</") !== false) {
      $str = preg_replace("/[\t\n\r ]*(<\/[^>]+>)/s", "$1", $str); // no space before closing tags
    }
    $str = preg_replace("/[\t ]*(\n|\r)[\t ]*/s", "$1", $str);
    $str = preg_replace("/(\n\r){3,}/s", "$1$1", $str);
    $str = preg_replace("/[\n]{3,}/s", "\n\n", $str);
    $str = preg_replace("/[ ]{2,}/s", ' ', $str);
    return trim($str);
  }

  public function get_excerpt($text = '', $length = 250, $args = array()) {
    if (empty($text)) {
      return $text;
    }
    // resolve vars
    $default_args = array(
      'allowable_tags' => array(),
      'plaintext' => true,
      'single_line' => true,
      'trim_title' => array(),
      'trim_urls' => true,
      'add_dots' => true,
    );
    $default_args = apply_filters('posttoemail_get_excerpt_default_args', $default_args);
    $args = $this->make_array($args);
    $args = array_merge($default_args, $args);
    if (function_exists('fix_potential_html_string')) {
      $text = fix_potential_html_string($text);
    }
    // add a space for lines if needed
    if ($args['single_line'] && strpos($text, "<") !== false) {
      $text = preg_replace("/(<p>|<p [^>]*>|<\/p>|<br>|<br\/>|<br \/>)/i", "$1 ", $text);
    }
    // remove what we don't need
    $args['allowable_tags'] = $this->make_array($args['allowable_tags']);
    if (!empty($args['allowable_tags']) && $args['plaintext'] === false) {
      // script/style tags - special case - remove all contents
      $strip_all = array('script', 'style');
      foreach ($strip_all as $value) {
        if (!array_key_exists($value, $args['allowable_tags'])) {
          $text = preg_replace("/<".$value."[^>]*>.*?<\/".$value.">/is", "", $text);
        }
      }
      $args['allowable_tags'] = '<'.implode('><', (array)$args['allowable_tags']).'>';
      $text = strip_tags($text, $args['allowable_tags']);
    }
    else {
      $text = wp_strip_all_tags($text, $args['single_line']);
    }
    $text = $this->strip_all_shortcodes($text);
    // remove excess space
    if ($args['single_line']) {
      $text = preg_replace("/[\n\r]+/s", " ", $text);
    }
    $text = preg_replace("/[\t]+/s", " ", $text); // no tabs
    $text = $this->trim_excess_space($text);
    // trim the top
    $regex_arr = array("(<br>|<br\/>|<br \/>)");
    $args['trim_title'] = $this->make_array($args['trim_title']);
    if (!empty($args['trim_title'])) {
      if (function_exists('fix_potential_html_string')) {
        $args['trim_title'] = array_map('fix_potential_html_string', $args['trim_title']);
      }
      $args['trim_title'] = array_map('trim', $args['trim_title']);
      $args['trim_title'] = array_unique($args['trim_title']);
      $args['trim_title'] = array_filter($args['trim_title']);
      foreach ($args['trim_title'] as $key => $value) {
        if (!empty($args['allowable_tags']) && $args['plaintext'] === false) {
          $regex_arr[] = "<[^>]+>[\s]*".$value;
        }
        $regex_arr[] = $value;
      }
    }
    if ($args['trim_urls']) {
        $regex = "((https?|ftp)://)"; // SCHEME
        $regex .= "([a-z0-9+!*(),;?&=\$_.-]+(:[a-z0-9+!*(),;?&=\$_.-]+)?@)?"; // User and Pass
        $regex .= "([a-z0-9-.]*)\.([a-z]{2,4})"; // Host or IP
        $regex .= "(:[0-9]{2,5})?"; // Port
        $regex .= "(/([a-z0-9+\$_%-]\.?)+)*/?"; // Path
        $regex .= "(\?[a-z+&\$_.-][a-z0-9;:@&%=+/\$_.-]*)?"; // GET Query
        $regex .= "(#[a-z_.-][a-z0-9+$%_.-]*)?"; // Anchor
      if (!empty($args['allowable_tags']) && $args['plaintext'] === false) {
        $regex_arr[] = "<[^>]+>[\s]*".$regex;
      }
      $regex_arr[] = $regex;
      
        $regex = "www\."; // SCHEME
        $regex .= "([a-z0-9-.]*)\.([a-z]{2,4})"; // Host or IP
        $regex .= "(:[0-9]{2,5})?"; // Port
        $regex .= "(/([a-z0-9+\$_%-]\.?)+)*/?"; // Path
        $regex .= "(\?[a-z+&\$_.-][a-z0-9;:@&%=+/\$_.-]*)?"; // GET Query
        $regex .= "(#[a-z_.-][a-z0-9+$%_.-]*)?"; // Anchor
      if (!empty($args['allowable_tags']) && $args['plaintext'] === false) {
        $regex_arr[] = "<[^>]+>[\s]*".$regex;
      }
      $regex_arr[] = $regex;
    }
    $i = 0;
    while ($i < count($regex_arr)) {
      $i = 0;
      foreach ($regex_arr as $key => $value) {
        $replaced = false;
        $pos = strpos($text, $value);
        if ($pos === 0) {
          $len_res = mb_strlen($text);
          $len_value = mb_strlen($value);
          if ($len_res > $len_value) {
            $text = preg_replace("/^".preg_quote($value, '/')."[\s]*/i", "", $text);
            $replaced = true;
          }
        }
        elseif (preg_match("~^[\s]*".preg_quote($value, '/')."~i", $text, $match)) {
          $len_res = mb_strlen($text);
          $len_value = mb_strlen($match[0]);
          if ($len_res > $len_value) {
            $text = preg_replace("/^[\s]*".preg_quote($match[0], '/')."[\s]*/i", "", $text);
            $replaced = true;
          }
        }
        if (!$replaced) {
          $i++;
        }
      }
    }
    // correct length
    // TODO: find a fast way of checking multibyte strings here
    if (strlen(strip_tags($text)) <= $length) {
      if ($args['plaintext']) {
        return $text;
      }
    }
    // add dots
    else {
      $length_new = $length;
      if ($args['plaintext'] && !preg_match("/[^a-z0-9]/i", mb_substr($text, $length, 1))) {
        $length_new = mb_strrpos( mb_substr($text, 0, $length), " ");
      }
      elseif (!preg_match("/[^a-z0-9>]/i", mb_substr($text, $length, 1))) {
        $length_new = mb_strrpos( mb_substr($text, 0, $length), " ");
      }
      $text = mb_substr($text,0,$length_new,'UTF-8');
      // check if we cut in the middle of a tag
      if (!empty($args['allowable_tags']) && $args['plaintext'] === false) {
        $tags = trim( str_replace('><', '|', $args['allowable_tags']), '><');
        $text = preg_replace("/^(.+)<($tags) [^>]+$/is", "$1", $text);
      }
      if ($args['add_dots']) {
        if ($args['plaintext']) {
          $text .= "...";
        }
        else {
          $text .= '&hellip;';
        }
        $text = preg_replace("/[^a-z0-9>]+(\.\.\.|&#8230;|&hellip;)[\s]*$/i", "$1", $text);
      }
    }
    // add line breaks?
    if ($args['single_line'] === false && $args['plaintext'] === false && strpos($text, '<br />') === false && strpos($text, '</') === false) {
      $text = nl2br($text);
      // TODO: cleanup br tags directly next to p tags
    }
    // close open tags
    if (!empty($args['allowable_tags']) && $args['plaintext'] === false) {
      if (function_exists('force_balance_tags')) {
        $text = force_balance_tags($text);
      }
      else {
        // puts plaintext in a p
        $dom = @DOMDocument::loadHTML( mb_convert_encoding($text, 'HTML-ENTITIES', 'UTF-8') );
        $text = trim( strip_tags( html_entity_decode( $dom->saveHTML() ), $args['allowable_tags'] ) );
      }
    }
    return $text;
  }

    /* functions - core */

  public function get_option($key = '', $default = array()) {
    $options = array();
    if ($value = $this->get_cache('plugin_options', null, false)) {
      $options = $value;
    }
    else {
      if (is_multisite()) {
        $value = get_site_option(self::$prefix, array());
      }
      else {
        $value = get_option(self::$prefix, array());
      }
      if ($value) {
        $this->set_cache('plugin_options', null, $value, false);
        $options = $value;
      }
    }
    if (!empty($key)) {
      if (array_key_exists($key, $options)) {
        return $options[$key];
      }
      return $default;
    }
    return $options;
  }
  public function get_blog_option($key = '', $default = array()) {
    if (!is_multisite()) {
      return $this->get_option($key, $default);
    }
    $options = array();
    if ($value = $this->get_cache('plugin_blog_options', null)) {
      $options = $value;
    }
    elseif ($value = get_blog_option(get_current_blog_id(), self::$prefix, array())) {
      $this->set_cache('plugin_blog_options', null, $value);
      $options = $value;
    }
    if (!empty($key)) {
      if (array_key_exists($key, $options)) {
        return $options[$key];
      }
      return $default;
    }
    return $options;
  }
  public function update_options($options = array()) {
    if (!empty($options)) {
      $options_old = $this->get_option();
      if (is_multisite()) {
        $bool = update_site_option(self::$prefix, $options);
      }
      else {
        $bool = update_option(self::$prefix, $options);
      }
      if (!$bool) {
        // were there changes?
        ksort($options_old);
        ksort($options);
        if ($options_old !== $options) {
          return false;
        }
      }
    }
    else {
      if (is_multisite()) {
        $bool = delete_site_option(self::$prefix);
      }
      else {
        $bool = delete_option(self::$prefix);
      }
      if (!$bool) {
        return false;
      }
    }
    $this->set_cache('plugin_options', null, $options, false);
    return true;
  }
  public function update_blog_options($options = array()) {
    if (!is_multisite()) {
      return $this->update_options($options);
    }
    if (!empty($options)) {
      $options_old = $this->get_blog_option();
      $bool = update_blog_option(get_current_blog_id(), self::$prefix, $options);
      if (!$bool) {
        // were there changes?
        ksort($options_old);
        ksort($options);
        if ($options_old !== $options) {
          return false;
        }
      }
    }
    else {
      $bool = delete_blog_option(get_current_blog_id(), self::$prefix);
      if (!$bool) {
        return false;
      }
    }
    $this->set_cache('plugin_blog_options', null, $options);
    return true;
  }

  public function get_users($sort = 'display_name', $scope = array()) {
    $user_args = array(
      'exclude' => self::$exclude_users,
      'orderby' => $sort,
      'fields' => array_merge(array('ID'), $this->get_wp_users_fields()),
    );
    $opt_in = $this->get_option('opt_in', false);
    $default_interval = $this->get_option('default_interval', 'never');
    if (!empty($scope)) {
      $scope = $this->make_array($scope);
      if ($opt_in) {
        $user_args['meta_query'] = array(
          'relation' => 'OR',
        );
        foreach ($scope as $value) {
          $user_args['meta_query'][] = array(
            'key' => self::$prefix,
            'compare' => 'LIKE',
            'value' => $value,
          );
        }
        if (in_array($default_interval, $scope)) {
          $user_args['meta_query'][] = array(
            'key' => self::$prefix,
            'compare' => 'NOT EXISTS',
          );
        }
      }
      else {
        if (!in_array($default_interval, $scope)) {
          return array();
        }
      }
    }
    if ($arr = $this->get_option('hidden_roles', false)) {
      $user_args['role__not_in'] = $arr;
    }
    if (!is_multisite()) {
      return get_users($user_args);
    }
    // multisite
    if (!$this->is_front_end() && !is_main_site() && self::$is_test) {
      return get_users($user_args);
    }
    $users = $users_arr = $sort_arr = array();
    $sites = get_sites();
    foreach ($sites as $key => $value) {
      $blog_users = get_users( array_merge($user_args, array('blog_id' => $value->blog_id)) );
      if (!empty($blog_users)) {
        foreach ($blog_users as $blog_user) {
          // avoid duplicates
          if (isset($users_arr[$blog_user->ID])) {
            continue;
          }
          $users_arr[$blog_user->ID] = $blog_user;
          $sort_arr[$blog_user->ID] = $blog_user->$sort;
        }
      }
    }
    if (!empty($users_arr)) {
      natcasesort($sort_arr);
      foreach ($sort_arr as $key => $value) {
        $users[] = $users_arr[$key];
      }
    }
    return $users;
  }

  public function update_user_meta($user_id, $key, $value = '') {
    if (self::$is_test) {
      return;
    }
    $usermeta = $this->get_cache_wp_usermeta_extended($user_id);
    $usermeta[$key] = $value;
    self::$cache['wp_usermeta_extended'][$user_id][$key] = $value;
    $usermeta = array_filter($usermeta);
    return update_user_meta($user_id, self::$prefix, $usermeta);
  }

  public function user_has_hidden_role($roles = array()) {
    $hidden_roles = $this->get_option('hidden_roles', array());
    if (empty($hidden_roles)) {
      return false;
    }
    $roles = $this->make_array($roles);
    if (empty($roles)) {
      return false;
    }
    foreach ($roles as $role) {
      if (in_array($role, $hidden_roles)) {
        return true;
      }
    }
    return false;
  }

    public function get_options_array($tab = null) {
      $arr = array(
          'general' => array(
        'active',
        'cron',
        'cron_direct',
        'admin_email',
        'opt_in',
        'default_interval',
        'hidden_roles',
      ),
          'post' => array(
        'post_types',
        'excluded_posts',
      ),
          'mail' => array(
        'mail_from',
        'mail_replyto',
        'mail_subject',
        'mail_stylesheet',
        'disable_richedit',
        'mail_message_header',
        'mail_message_excerpt',
        'mail_message_excerpt_length',
        'mail_message_footer',
      ),
      );
      if (self::$has_buddypress) {
          $arr['buddypress'] = array(
        'buddypress_reminder_active',
        'buddypress_reminder_interval',
        'buddypress_reminder_fields_age',
        'buddypress_reminder_message',
          );
      }
      if (self::$has_bbpress) {
          $arr['bbpress'] = array(
        'bbpress_use_subs',
          );
      }
      if (!empty($tab) && isset($arr[$tab])) {
        return $arr[$tab];
      }
      // flat array
      $res = array();
      foreach ($arr as $key => $value) {
        $res = array_merge($res, $value);
      }
      return $res;
    }
    public function get_usermeta_array() {
    $arr = array(
      'interval',
      'last_sent',
    );
      if (self::$has_buddypress) {
          $arr[] = 'last_sent_buddypress_reminder';
      }
      return $arr;
    }

    /* functions - hash fields */

  public function get_wp_users_fields() {
    return array(
      'user_login',
      'user_email',
      'user_url',
      'display_name',
    );
  }
    public function get_wp_posts_extended_fields($fields = 'excerpt') {
    if ($fields == 'excerpt' && $value = $this->get_cache('wp_posts_extended_fields', null, false)) {
      return $value;
    }
    $arr = array(
      'post_title',
      'post_url',
      'post_date',
      'post_author',
      'post_excerpt',
      'post_parent_title',
      'post_parent_url',
    );
    if (is_multisite()) {
      $arr = array_merge($arr, array('blogname', 'siteurl'));
    }
    if ($fields == 'all') {
      return $arr;
    }
    $fields = array();
      $str = $this->get_option('mail_message_excerpt', '');
      foreach ($arr as $value) {
        if ($this->has_hash($str, strtoupper($value))) {
          $fields[] = $value;
        }
      }
    $this->set_cache('wp_posts_extended_fields', null, $fields, false);
    return $fields;
    }
  public function get_buddypress_xprofile_fields($fields = 'reminder') {
    if ($fields == 'reminder' && $value = $this->get_cache('buddypress_xprofile_fields', null, false)) {
      return $value;
    }
    $arr = array();
    if ( bp_has_profile() ) {
      while ( bp_profile_groups() ) {
        bp_the_profile_group();
        while ( bp_profile_fields() ) {
          bp_the_profile_field();
          $arr[] = str_replace(' ', '_', bp_get_the_profile_field_name());
        }
      }
    }
    if (empty($arr)) {
      return false;
    }
    if ($fields == 'all') {
      return $arr;
    }
    $fields = array();
      $str = $this->get_option('buddypress_reminder_message', '');
      foreach ($arr as $value) {
        if ($this->has_hash($str, $value)) {
          $fields[] = $value;
        }
      }
    $this->set_cache('buddypress_xprofile_fields', null, $fields, false);
    return $fields;
  }

  private function has_hash($str = '', $key) {
    if (strpos($str, self::$hash.$key) !== false) {
      return true;
    }
    if (strpos($str, $key.self::$hash) !== false) {
      return true;
    }
    return false;
  }
  private function replace_hash($str = '', $scope = array('user')) {
    if (empty($str)) {
      return $str;
    }
    $scope = $this->make_array($scope);

    $replace = array(
      'DATE' => date($this->get_cache_wp_options('date_format', 'd/m/Y'), self::$time),
    );
    if ($this->has_hash($str, 'BLOGNAME')) {
      $replace['BLOGNAME'] = $this->get_cache_main_blog_options('blogname', '');
    }
    if ($this->has_hash($str, 'SITEURL')) {
      $replace['SITEURL'] = esc_url($this->get_cache_main_blog_options('siteurl', ''));
    }

    if (in_array('user', $scope) && isset($this->current_user) && !empty($this->current_user)) {
      $fields = $this->get_wp_users_fields();
      foreach ($fields as $value) {
        $key = strtoupper($value);
        $replace[$key] = $this->current_user->$value;
      }
    }

    if (in_array('post', $scope) && isset($this->current_post) && !empty($this->current_post)) {
      $fields = $this->get_wp_posts_extended_fields();
      foreach ($fields as $value) {
        $key = strtoupper($value);
        $replace[$key] = $this->current_post[$value];
      }
    }

    if (self::$has_buddypress && isset($this->current_user) && !empty($this->current_user)) {
      // xprofile fields
      if (in_array('buddypress_reminder', $scope)) {
        if ($fields = $this->get_buddypress_xprofile_fields()) {
          foreach ($fields as $value) {
            $value_spaced = str_replace('_', ' ', $value);
            $field_id = xprofile_get_field_id_from_name($value_spaced);
            $data = xprofile_get_field_data($field_id, $this->current_user->ID);
            if (is_array($data)) {
              $data = implode(", ", $data);
            }
            if (empty($data)) {
              $data = '('.$value_spaced.')';
            }
            $replace[$value] = (string)$data;
          }
        }
      }
      // reminder message
      elseif ($this->has_hash($str, 'BUDDYPRESS_REMINDER')) {
        $func = function($str = '') {
          $active = $this->get_option('buddypress_reminder_active', false);
          if (empty($active)) {
            return $str; // not active
          }
          $interval = $this->get_option('buddypress_reminder_interval', false);
          if (!empty($interval) && $interval_time = strtotime("-".trim($interval, "-"), self::$time)) {
            $usermeta = $this->get_cache_wp_usermeta_extended($this->current_user->ID);
            if (!empty($usermeta['last_sent_buddypress_reminder'])) {
              $date_past = date(self::$date_format, $interval_time);
              if ($usermeta['last_sent_buddypress_reminder'] > $date_past) {
                return $str; // not ready
              }
            }
          }
          $fields_age = $this->get_option('buddypress_reminder_fields_age', false);
          if (!empty($fields_age) && $fields_age_time = strtotime("-".trim($fields_age, "-"), self::$time)) {
            global $wpdb;
            $bp = buddypress();
            $last_updated = $wpdb->get_row( $wpdb->prepare("SELECT last_updated, f.* FROM {$bp->profile->table_name_data} d RIGHT JOIN {$bp->profile->table_name_fields} f ON d.field_id = f.id WHERE user_id = %d ORDER BY last_updated DESC LIMIT 1", $this->current_user->ID) );
            if (!empty($last_updated)) {
              $date_past = date(self::$date_format, $fields_age_time);
              if ($last_updated->last_updated > $date_past) {
                return $str; // reminder not needed
              }
            }
          }
          $str = $this->get_option('buddypress_reminder_message', '');
          if (!empty($str)) {
            $str = $this->replace_hash($str, array('user','buddypress_reminder'));
            $this->update_user_meta($this->current_user->ID, 'last_sent_buddypress_reminder', date(self::$date_format, self::$time));
          }
          return $str;
        };
        $replace['BUDDYPRESS_REMINDER'] = $func();
      }
    }

    $replace_hash = function ($str) {
      return self::$hash.$str.self::$hash;
    };
    // exact matches
    $str = str_replace(array_map($replace_hash, array_keys($replace)), $replace, $str);
    // partial matches
    if (strpos($str, self::$hash) !== false) {
      $hash_short = substr(self::$hash, 0, 1);
      foreach ($replace as $key => $value) {
        if (empty($value)) {
          $str = preg_replace("/".self::$hash.$key."([^".$hash_short."_]*)".self::$hash."/is", "", $str);
          $str = preg_replace("/".self::$hash."([^".$hash_short."_]*)".$key.self::$hash."/is", "", $str);
        }
        else {
          $str = preg_replace("/".self::$hash.$key."([^".$hash_short."_]*)".self::$hash."/is", $value."$1", $str);
          $str = preg_replace("/".self::$hash."([^".$hash_short."_]*)".$key.self::$hash."/is", "$1".$value, $str);
        }
      }
    }
    return $str;
  }

  /* functions - cron */

  public function cron_toggle($force = null) {
    $cron = false;
    // use option
    if (is_null($force)) {
      $option = $this->get_option('cron', false);
      if (!empty($option)) {
        $cron = true;
      }
    }
    elseif ($force) {
      $cron = true;
    }

    $timestamp = wp_next_scheduled(self::$prefix.'_cron');
    if (!$cron && !$timestamp) {
      return;
    }
    elseif ($cron && $timestamp) {
      return;
    }
    elseif (!$cron && $timestamp) {
      wp_unschedule_event($timestamp, self::$prefix.'_cron');
      wp_clear_scheduled_hook(self::$prefix.'_cron');
    }
    elseif ($cron && !$timestamp) {
      wp_clear_scheduled_hook(self::$prefix.'_cron');
      $time = strtotime("midnight tomorrow", self::$time);
      wp_schedule_event($time, 'twicedaily', self::$prefix.'_cron');
    }
  }

  /* functions - cache */

  private function set_cache($group, $id = null, $value, $blog_id = null) {
    if (!isset(self::$cache[$group])) {
      self::$cache[$group] = array();
    }
    if (is_multisite()) {
      if (is_null($blog_id)) {
        $blog_id = get_current_blog_id();
      }
    }
    // group - blog_id - id - value
    if (!empty($blog_id)) {
      $blog_id = absint($blog_id);
      if (!isset(self::$cache[$group][$blog_id])) {
        self::$cache[$group][$blog_id] = array();
      }
      if (empty($id)) {
        self::$cache[$group][$blog_id] = $value;
      }
      else {
        self::$cache[$group][$blog_id][$id] = $value;
      }
    }
    // group - id - value
    else {
      if (empty($id)) {
        self::$cache[$group] = $value;
      }
      else {
        self::$cache[$group][$id] = $value;
      }
    }
  }
  private function get_cache($group, $id = null, $blog_id = null) {
    if (is_multisite()) {
      if (is_null($blog_id)) {
        $blog_id = get_current_blog_id();
      }
    }
    if (!empty($blog_id)) {
      $blog_id = absint($blog_id);
      if (empty($id) && isset(self::$cache[$group][$blog_id])) {
        return self::$cache[$group][$blog_id];
      }
      elseif (isset(self::$cache[$group][$blog_id][$id])) {
        return self::$cache[$group][$blog_id][$id];
      }
    }
    else {
      if (empty($id) && isset(self::$cache[$group])) {
        return self::$cache[$group];
      }
      elseif (isset(self::$cache[$group][$id])) {
        return self::$cache[$group][$id];
      }
    }
    return false;
  }
    public function get_cache_wp_options($key, $default = '') {
      if ($value = $this->get_cache('wp_options', $key)) {
        return $value;
      }
    if ($value = get_option($key, $default)) {
      $this->set_cache('wp_options', $key, $value);
      return $value;
    }
    return $default;
    }
    public function get_cache_main_blog_options($key, $default = '') {
      if (!is_multisite()) {
        return $this->get_cache_wp_options($key, $default);
      }
      if ($value = $this->get_cache('main_blog_options', $key, false)) {
        return $value;
      }
    if ($value = get_blog_option($this->get_main_blog_id(), $key, $default)) {
      $this->set_cache('main_blog_options', $key, $value, false);
      return $value;
    }
    return $default;
    }
    public function get_cache_wp_posts_extended($post_id, $post = array(), $mail_date = 'post_date', $fields = 'excerpt') {
    if ($value = $this->get_cache('wp_posts_extended', $post_id)) {
      return $value;
    }
      if (!is_array($post)) {
        $post = get_post($post, ARRAY_A);
      }
      if (empty($post)) {
        return false;
      }
    $fields = $this->get_wp_posts_extended_fields($fields);
      if (in_array('post_url', $fields)) {
      $post['post_url'] = esc_url(get_permalink($post['ID']));
      }
      if (in_array('post_date', $fields)) {
        if ($mail_date == 'post_date') {
          $str = $post['post_date'];
      }
      else {
          $str = $post['post_modified'];
      }
      $post['post_date'] = date($this->get_cache_wp_options('date_format', 'd/m/Y'), strtotime($str));
      }
      if (in_array('post_author', $fields)) {
        if ($mail_date == 'post_date') {
        $post['post_author'] = $this->get_the_author_by_post_id($post['ID']);
      }
      else {
        $post['post_author'] = $this->get_the_modified_author_by_post_id($post['ID']);
      }
      }
      if (in_array('post_excerpt', $fields)) {
        $str = $post['post_excerpt'];
      $str = $this->strip_all_shortcodes($str);
      $str = apply_filters('the_excerpt', $str);
        if (empty($str)) {
          $str = $post['post_content'];
        $str = $this->strip_all_shortcodes($str);
        $str = apply_filters('the_content', $str);
        }
        $post['post_excerpt'] = $this->get_excerpt($str, $this->get_option('mail_message_excerpt_length', 250), array('trim_title' => $post['post_title']));
      }
      if (in_array('post_parent_title', $fields)) {
      $post['post_parent_title'] = get_post_field('post_title', $post['post_parent']);
      }
      if (in_array('post_parent_url', $fields)) {
      $post['post_parent_url'] = esc_url(get_permalink($post['post_parent']));
      }
      if (is_multisite()) {
        if (in_array('blogname', $fields)) {
        $post['blogname'] = $this->get_cache_wp_options('blogname', '');
      }
        if (in_array('siteurl', $fields)) {
        $post['siteurl'] =  esc_url($this->get_cache_wp_options('siteurl', ''));
      }
      }
      $this->set_cache('wp_posts_extended', $post_id, $post);
      return $post;
    }
    public function get_cache_wp_usermeta_extended($user_id = 0) {
    if ($value = $this->get_cache('wp_usermeta_extended', $user_id, false)) {
      return $value;
    }
    $usermeta = get_user_meta($user_id, self::$prefix, true);
    $usermeta = $this->make_array($usermeta);
    $usermeta_arr = $this->get_usermeta_array();
    $usermeta = array_merge( array_fill_keys($usermeta_arr, null), $usermeta );
      $this->set_cache('wp_usermeta_extended', $user_id, $usermeta, false);
    return $usermeta;
  }

  /* functions - mail */

  private function get_posts_extended($options, $last_sent) {
    if (!isset($this->current_user) || empty($this->current_user)) {
      return false;
    }
    $posts = array();

    $func = function() use ($options, $last_sent, &$posts) {
      // blog specific
      $blog_options = $this->get_blog_option();

      $blog_options['post_types'] = $this->make_array($blog_options['post_types']);
      if (empty($blog_options['post_types'])) {
        return false;
      }
      $blog_options['excluded_posts'] = $this->make_array($blog_options['excluded_posts']);
      $blog_options['excluded_posts'] = apply_filters('posttoemail_excluded_posts', $blog_options['excluded_posts']);

      if (self::$has_bbpress) {
        if (!empty($options['bbpress_use_subs'])) {
          $bbpress_subs = array_merge(bbp_get_user_subscribed_forum_ids($this->current_user->ID), bbp_get_user_subscribed_topic_ids($this->current_user->ID));
        }
      }

      foreach ($blog_options['post_types'] as $post_type => $mail_date) {
        $args = array(
          'post_type' => $post_type,
          'post__not_in' => $blog_options['excluded_posts'],
          'post_parent__not_in' => $blog_options['excluded_posts'],
          'posts_per_page' => -1,
          'no_found_rows' => true,
          'nopaging' => true,
          'ignore_sticky_posts' => true,
          'suppress_filters' => false,
          'orderby' => array('parent' => 'ASC'),
        );
        if ($mail_date == 'post_date') {
          $args['orderby']['date'] = 'DESC';
          $args['date_query'] = array(
            array(
              'column' => 'post_date_gmt',
              'after'  => $last_sent,
            ),
          );
        }
        else {
          $args['orderby']['modified'] = 'DESC';
          $args['date_query'] = array(
            array(
              'column' => 'post_modified_gmt',
              'after'  => $last_sent,
            ),
          );
        }
        $arr = get_posts($args);
            if (!empty($arr)) {
              foreach ($arr as $key => $value) {
                // check ancestors
            if (!empty($blog_options['excluded_posts'])) {
              $ancestors = get_ancestors($value->ID, $value->post_type);
              if (!empty($ancestors)) {
                $diff = array_diff($ancestors, $blog_options['excluded_posts']);
                if ($diff !== $ancestors) {
                  unset($arr[$key]);
                  continue;
                }
              }
            }
            // bbpress subs
            if (self::$has_bbpress) {
              if (!empty($options['bbpress_use_subs']) && in_array($value->post_type, $this->bbpress_post_types)) {
                if (!in_array($value->ID, $bbpress_subs) && !in_array($value->post_parent, $bbpress_subs)) {
                  unset($arr[$key]);
                  continue;
                }
              }
            }
            // add to results
                if ($post = $this->get_cache_wp_posts_extended($value->ID, $value, $mail_date)) {
                  $posts[] = $post;
                }
              }
            }
      }
    };

    if (is_multisite()) {
      $blogs = apply_filters('posttoemail_get_blogs_of_user', get_blogs_of_user($this->current_user->ID));
      foreach ($blogs as $blog_id => $blog) {
        switch_to_blog($blog_id);
        $func();
        restore_current_blog();
      }
    }
    else {
      $func();
    }
        return $posts;
  }

  public function get_message_array($options = array(), $userdata) {
    if (!is_object($userdata)) {
      return false;
    }
    if (!isset($userdata->user_email) || empty($userdata->user_email)) {
      return false;
    }
    $usermeta = $this->get_cache_wp_usermeta_extended($userdata->ID);

    // check interval
    $interval = '';
    if (isset($options['opt_in']) && !empty($options['opt_in'])) {
      if (isset($usermeta['interval']) && !empty($usermeta['interval'])) {
        $interval = $usermeta['interval'];
      }
    }
    if (empty($interval) && isset($options['default_interval']) && !empty($options['default_interval'])) {
      $interval = $options['default_interval'];
    }
    if (empty($interval) || $interval == 'never') {
      return false;
    }

    $userLogString = "UserID: " . $userdata->ID . ", email: " . $userdata->user_email;

    // check last_sent
    switch ($interval) {
      case 'daily':
        $interval_last_sent = date(self::$date_format, strtotime("midnight", self::$time));
        break;
      case 'weekly':
        global $wp_locale;
        $start_of_week = $wp_locale->get_weekday($this->get_cache_wp_options('start_of_week', 1));
        if (strtolower(date('l')) == $start_of_week) {
          // if (say start of week is Monday and) it is Monday, then the cutoff for last sent
          // should be last midnight
          $interval_last_sent = date(self::$date_format, strtotime("midnight", self::$time));
        } else {
          // but if it is, say, Tuesday then we want last Monday (yesterday)
          $interval_last_sent = date(self::$date_format, strtotime("last ".$start_of_week, self::$time));
        }
        break;
      case 'monthly':
      default:
        $interval_last_sent = date('Y-m-01 00:00:00', self::$time);
        break;
    }

    post_to_email_log($userLogString . " intervalLastSent: ".$interval_last_sent." user last_sent: ".$usermeta['last_sent']);

    if (isset($usermeta['last_sent']) && !empty($usermeta['last_sent'])) {
      if ($usermeta['last_sent'] > $interval_last_sent) {
        post_to_email_log($userLogString . " ending because user last_sent > interval_last_sent");
        return false; // not ready
      }
      $last_sent = $usermeta['last_sent'];
      // limit to 1 month (or more) of back posts
      $past_month = date('Y-m-01 00:00:00', strtotime("-1 month", self::$time));
      if ($past_month > $last_sent) {
        $last_sent = $past_month;
      }
    }
    else {
      $last_sent = $interval_last_sent;
    }

    post_to_email_log($userLogString . " last_sent=".$last_sent);

    $this->current_user = $userdata;

    $posts = $this->get_posts_extended($options, $last_sent);
    
    post_to_email_log($userLogString . " post count to send: ".count($posts));

    if (empty($posts)) {
      unset($this->current_user);
      post_to_email_log($userLogString . " ending because no posts");
      return false;
    }

    $str = '';

    if (empty($options['disable_richedit'])) {
      $str .= wpautop($this->replace_hash($options['mail_message_header']));
      foreach ($posts as $value) {
        $this->current_post = $value;
        $str .= wpautop($this->replace_hash($options['mail_message_excerpt'], array('user','post')));
      }
      unset($this->current_post);
      $str .= wpautop($this->replace_hash($options['mail_message_footer']));
    }
    else {
      $str .= $this->replace_hash($options['mail_message_header']);
      foreach ($posts as $value) {
        $this->current_post = $value;
        $str .= $this->replace_hash($options['mail_message_excerpt'], array('user','post'));
      }
      unset($this->current_post);
      $str .= $this->replace_hash($options['mail_message_footer']);
    }

    $str = trim($str);
    if (empty($str)) {
      unset($this->current_user);
      return false;
    }

    // wrap html
    $message = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'."\n";
    $message .= '<html xmlns="http://www.w3.org/1999/xhtml">'."\n";
    $message .= '<head>'."\n";
    $message .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'."\n";
    $message .= '<title></title>'."\n";
    // css
    if (!isset($this->current_css)) {
      $css = '';
      if (!empty($options['mail_stylesheet']) && strpos($options['mail_stylesheet'], '.css') !== false) {
        if ($css_file = $this->get_file_contents($options['mail_stylesheet'])) {
          $css = '<style type="text/css">';
          $css .= $css_file;
          $css .= '</style>';
          $replace = array(
            "\n" => '',
            "\t" => '',
          );
          $css = str_replace(array_keys($replace), $replace, $css);
          $css = $this->trim_excess_space($css)."\n";
        }
      }
      $this->current_css = $css;
    }
    if (isset($this->current_css) && !empty($this->current_css)) {
      $message .= $this->current_css;
    }
    $message .= '</head>'."\n";
    $message .= '<body>'."\n";
    $message .= $str."\n";
    $message .= '</body>'."\n";
    $message .= '</html>'."\n";

    $arr = array(
      'message' => $message,
      'subject' => $this->replace_hash($options['mail_subject']),
    );
    if (isset($this->current_user->display_name) && !empty($this->current_user->display_name)) {
      $arr['to'] = $this->current_user->display_name.' <'.$this->current_user->user_email.'>';
    }
    else {
      $arr['to'] = $this->current_user->user_email;
    }
    $arr = apply_filters('posttoemail_message_array', $arr, $options, $userdata);
    post_to_email_log($userLogString . " successfully built email");
    return $arr;
  }

  public function mail($options = array(), $to, $subject, $message) {
    $headers = array();
    if (isset($options['mail_from']) && !empty($options['mail_from'])) {
      $headers[] = 'From: '.$options['mail_from'];
    }
    if (isset($options['mail_replyto']) && !empty($options['mail_replyto'])) {
      $headers[] = 'Reply-To: '.$options['mail_replyto'];
    }
    $headers[] = 'Precedence: bulk';
    $headers[] = 'Content-Type: text/html; charset="UTF-8"';
    if (wp_mail($to, $subject, $message, $headers)) {
      $this->update_user_meta($this->current_user->ID, 'last_sent', date(self::$date_format, self::$time));
      $res = true;
    }
    else {
      $res = false;
    }
    unset($this->current_user);
    return $res;
  }

}
endif;
?>
