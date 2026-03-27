<?php
add_action('admin_init', function () {
    register_setting('git_plugin_settings_group', 'git_plugin_local_path', [
        'type' => 'string',
        'sanitize_callback' => 'git_plugin_validate_local_path',
        'default' => ''
    ]);

    register_setting('git_plugin_settings_group', 'git_plugin_remote_url', [
        'type' => 'string',
        'sanitize_callback' => 'git_plugin_validate_remote_url',
        'default' => ''
    ]);
});

// ===============================
// Admin Menu: Create Git Branch
// ===============================
add_action('admin_menu', function () {
    add_menu_page(
        'Create Git Branch',
        'Create Branch',
        'manage_options',
        'create-git-branch',
        'render_git_settings_page',
        'dashicons-randomize',
        80
    );
});
