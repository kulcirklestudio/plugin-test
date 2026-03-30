<?php
add_action('admin_init', function () {
    register_setting('git_plugin_settings_group', 'git_plugin_local_path', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
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

function validate_git_path($path)
{
    $path = trim($path);

    if (empty($path)) {
        return '';
    }


    $real = realpath($path);

    if (!$real || !is_dir($real)) {
        add_settings_error(
            'git_plugin_local_path',
            'invalid_path',
            'Invalid path or folder does not exist'
        );
        return get_option('git_plugin_local_path');
    }

    if (!is_dir($real . '/.git')) {
        add_settings_error(
            'git_plugin_local_path',
            'not_git_repo',
            'This is not a Git repository'
        );
        return get_option('git_plugin_local_path');
    }

    // 🔒 Restrict path (IMPORTANT — you skipped this earlier)
    $allowed_base = realpath(WP_CONTENT_DIR . '/git-sync/');
    if ($allowed_base && strpos($real, $allowed_base) !== 0) {
        add_settings_error(
            'git_plugin_local_path',
            'not_allowed',
            'Path must be inside /wp-content/git-sync/'
        );
        return get_option('git_plugin_local_path');
    }

    return $real;
}