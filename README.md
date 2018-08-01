# wp-halftheory-post-to-email
Wordpress plugin for automatically sending any post to email, including digest options.

This plugin automatically mails site users when you create or modify a post.

Features:
- Limit mails to selected post types.
- Exclude selected posts amd their children from being automatically mailed.
- Simple admin options for contructing beautiful HTML emails (including CSS stylesheet).
- Allows users to choose between daily, weekly, or monthly email digests.
- Compatible with WP Multisite, Buddypress, Bbpress.

# Custom filters

The following filters are available for plugin/theme customization:
- posttoemail_admin_menu_parent
- posttoemail_post_types
- posttoemail_excluded_posts
- posttoemail_exclude_users
- posttoemail_default_author
- posttoemail_get_excerpt_default_args
- posttoemail_get_blogs_of_user
- posttoemail_message_array
- posttoemail_option_defaults
- posttoemail_deactivation
- posttoemail_uninstall
- halftheory_admin_menu_parent

# Debugging notes
* For each user, the interval they are set to receive it at, plus the time at which it was last sent to them, are stored (along with other data) in a JSON object in a single field in the database. So it is not so easy to do, e.g., range queries on it. So the best way to tell how many people were sent the digest on a certain day is to do something like this:

`SELECT * FROM gcawp_usermeta WHERE meta_key = "posttoemail" and meta_value like "%weekly%" and meta_value like "%2018-08-31%";`


