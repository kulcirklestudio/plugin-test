<?php
/*
Plugin Name: Git Connect
Plugin URI: https://example.com
Description: Manage your local Git repository directly from the WordPress admin. Create branches, commit changes, pull updates, and push code with a simple interface..
Version: 1.0
Author: Kuldeep Patel
Author URI: https://example.com
License: GPLv2 or later
Text Domain: git-connect-plugin
*/

require_once plugin_dir_path(__FILE__) . 'includes/helper.php';
require_once plugin_dir_path(__FILE__) . 'includes/actions.php';
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/ui.php';

// ======================================
// Load CSS for Only Plugin settings page
// ======================================
function git_plugin_enqueue_styles($hook)
{
    if ($hook !== 'toplevel_page_create-git-branch') {
        return;
    }

    wp_enqueue_style(
        'git-plugin-style',
        plugin_dir_url(__FILE__) . 'style.css',
        array(),
        '1.0.0',
        'all'
    );
}
add_action('admin_enqueue_scripts', 'git_plugin_enqueue_styles');