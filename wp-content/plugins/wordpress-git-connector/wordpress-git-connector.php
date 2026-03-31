<?php
/**
 * Plugin Name: WordPress Git Connector
 * Description: Manage a Git repository from the WordPress admin using buttons instead of the CLI.
 * Version: 1.0.0
 * Author: Codex
 * License: GPL-2.0-or-later
 * Text Domain: wordpress-git-connector
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-wordpress-git-connector.php';

new WordPress_Git_Connector();
