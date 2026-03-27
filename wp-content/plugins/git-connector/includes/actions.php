<?php
function git_plugin_handle_connect_remote()
{
    $path = git_plugin_get_configured_local_path();
    $remote_url = git_plugin_get_saved_remote_url();

    if ($path === false) {
        return;
    }

    if ($remote_url === '') {
        git_plugin_add_notice('missing_remote_url', 'Set a remote URL before connecting.');
        return;
    }

    $path_was_repo = git_is_git_repository($path);
    $directory_has_files = git_directory_has_non_git_files($path);

    if (!$path_was_repo) {
        $init_result = run_git_command($path, 'init');

        if ($init_result['status'] !== 0) {
            git_plugin_add_notice('init_failed', implode("\n", $init_result['output']), 'error');
            return;
        }

        git_plugin_add_notice('repo_initialized', 'Initialized a new Git repository in the selected local path.', 'updated');
    }

    $remote_name = git_find_remote_by_url($path, $remote_url);

    if ($remote_name !== '') {
        git_plugin_add_notice(
            'remote_exists',
            'Repository is already connected to this remote using "' . $remote_name . '".',
            'updated'
        );
    } else {
        $origin_url = git_get_remote_url($path, 'origin');

        if ($origin_url !== '') {
            $connect_result = run_git_command($path, 'remote set-url origin ' . escapeshellarg($remote_url));
            $remote_name = 'origin';
            $success_message = 'Updated the origin remote URL.';
        } else {
            $connect_result = run_git_command($path, 'remote add origin ' . escapeshellarg($remote_url));
            $remote_name = 'origin';
            $success_message = 'Connected the local repository to the remote as origin.';
        }

        if ($connect_result['status'] !== 0) {
            git_plugin_add_notice('remote_connect_failed', implode("\n", $connect_result['output']), 'error');
            return;
        }

        git_plugin_add_notice('remote_connected', $success_message, 'updated');
    }

    $fetch_result = run_git_command($path, 'fetch ' . escapeshellarg($remote_name) . ' --prune');

    if ($fetch_result['status'] !== 0) {
        git_plugin_add_notice(
            'fetch_failed',
            'Remote was saved, but fetch failed. Check repository access and credentials.' . "\n" . implode("\n", $fetch_result['output']),
            'error'
        );
        return;
    }

    git_plugin_add_notice('fetch_success', 'Fetched the latest remote references.', 'updated');

    $current_branch = git_get_current_branch($path);
    $default_branch = git_get_default_branch($path, $remote_name);

    if ($current_branch === '' && $default_branch !== '') {
        if ($directory_has_files) {
            git_plugin_add_notice(
                'manual_checkout_needed',
                'Remote was connected, but no branch was checked out automatically because the local folder already contains files.',
                'updated'
            );
            return;
        }

        $checkout_result = run_git_command(
            $path,
            'checkout -B ' . escapeshellarg($default_branch) . ' --track ' . escapeshellarg($remote_name . '/' . $default_branch)
        );

        if ($checkout_result['status'] !== 0) {
            git_plugin_add_notice(
                'checkout_failed',
                'Remote was connected, but automatic checkout of the default branch failed.' . "\n" . implode("\n", $checkout_result['output']),
                'error'
            );
            return;
        }

        git_plugin_add_notice(
            'checkout_success',
            'Checked out the remote default branch "' . $default_branch . '".',
            'updated'
        );
    }
}

add_action('admin_init', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (
        isset($_POST['git_connect_remote'], $_POST['git_connect_remote_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_connect_remote_nonce'])), 'git_connect_remote_action')
    ) {
        git_plugin_handle_connect_remote();
    }

    if (
        isset($_POST['test_git_repo'], $_POST['test_git_repo_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['test_git_repo_nonce'])), 'test_git_repo_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        $result = run_git_command($path, 'status');

        git_plugin_add_notice(
            'test_repo_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );
    }

    if (
        isset($_POST['switch_branch'], $_POST['select_branch_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['select_branch_nonce'])), 'select_branch_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        $branch = git_validate_branch_name($path, sanitize_text_field(wp_unslash($_POST['branch'] ?? '')));

        if (!$branch) {
            return;
        }

        if (!git_branch_exists($path, $branch)) {
            git_plugin_add_notice('missing_branch', 'Selected branch does not exist.');
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_add_notice('dirty_repo', 'Commit or stash changes before switching branches.');
            return;
        }

        if ($branch === git_get_current_branch($path)) {
            git_plugin_add_notice('already_on_branch', 'That branch is already active.', 'updated');
            return;
        }

        $result = run_git_command($path, 'checkout ' . escapeshellarg($branch));

        git_plugin_add_notice(
            'switch_branch_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );
    }

    if (
        isset($_POST['git_commit'], $_POST['git_commit_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_commit_nonce'])), 'git_commit_action')
    ) {
        $path = git_get_valid_repo_path();
        $message = sanitize_text_field(wp_unslash($_POST['commit_message'] ?? ''));

        if (!$path || $message === '') {
            return;
        }

        if (git_is_repo_clean($path)) {
            git_plugin_add_notice('no_changes', 'No changes to commit.');
            return;
        }

        $stage_result = run_git_command($path, 'add -A');

        if ($stage_result['status'] !== 0) {
            git_plugin_add_notice('stage_failed', implode("\n", $stage_result['output']), 'error');
            return;
        }

        $result = run_git_command($path, 'commit -m ' . escapeshellarg($message));

        git_plugin_add_notice(
            'commit_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );
    }

    if (
        isset($_POST['git_pull'], $_POST['git_pull_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_pull_nonce'])), 'git_pull_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_add_notice('dirty_repo', 'Commit or stash changes before pulling.');
            return;
        }

        if (!git_has_remote($path)) {
            git_plugin_add_notice('no_remote', 'No remote repository is connected yet.');
            return;
        }

        $upstream_check = run_git_command($path, 'rev-parse --abbrev-ref --symbolic-full-name @{u}');

        if ($upstream_check['status'] !== 0) {
            git_plugin_add_notice('no_upstream', 'Current branch has no upstream branch. Push once to set it.');
            return;
        }

        $result = run_git_command($path, 'pull');

        git_plugin_add_notice(
            'pull_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );
    }

    if (
        isset($_POST['git_push'], $_POST['git_push_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_push_nonce'])), 'git_push_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_add_notice('dirty_repo', 'Commit changes before pushing.');
            return;
        }

        $remote_name = git_get_primary_remote($path);

        if ($remote_name === '') {
            git_plugin_add_notice('no_remote', 'No remote repository is connected yet.');
            return;
        }

        $branch = git_get_current_branch($path);

        if ($branch === '') {
            git_plugin_add_notice('no_branch', 'Could not detect the current branch.');
            return;
        }

        $upstream_check = run_git_command($path, 'rev-parse --abbrev-ref --symbolic-full-name @{u}');

        if ($upstream_check['status'] !== 0) {
            $result = run_git_command($path, 'push -u ' . escapeshellarg($remote_name) . ' ' . escapeshellarg($branch));
        } else {
            $result = run_git_command($path, 'push');
        }

        git_plugin_add_notice(
            'push_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );
    }

    if (
        isset($_POST['git_create_branch'], $_POST['git_create_branch_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_create_branch_nonce'])), 'git_create_branch_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        $branch = git_validate_branch_name($path, sanitize_text_field(wp_unslash($_POST['new_branch'] ?? '')));

        if (!$branch) {
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_add_notice('dirty_repo', 'Commit changes before creating a branch.');
            return;
        }

        if (git_branch_exists($path, $branch)) {
            git_plugin_add_notice('branch_exists', 'Branch already exists.');
            return;
        }

        $result = run_git_command($path, 'checkout -b ' . escapeshellarg($branch));

        git_plugin_add_notice(
            'branch_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );
    }

    if (
        isset($_POST['git_merge_branch'], $_POST['git_merge_branch_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_merge_branch_nonce'])), 'git_merge_branch_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        $branch = git_validate_branch_name($path, sanitize_text_field(wp_unslash($_POST['merge_branch'] ?? '')));

        if (!$branch) {
            return;
        }

        if (!git_branch_exists($path, $branch)) {
            git_plugin_add_notice('missing_branch', 'Selected branch does not exist.');
            return;
        }

        if ($branch === git_get_current_branch($path)) {
            git_plugin_add_notice('same_branch_merge', 'Cannot merge the active branch into itself.');
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_add_notice('dirty_repo', 'Commit changes before merging.');
            return;
        }

        $result = run_git_command($path, 'merge ' . escapeshellarg($branch));

        git_plugin_add_notice(
            'merge_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );
    }

    if (
        isset($_POST['git_delete_branch'], $_POST['git_delete_branch_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['git_delete_branch_nonce'])), 'git_delete_branch_action')
    ) {
        $path = git_get_valid_repo_path();

        if (!$path) {
            return;
        }

        $branch = git_validate_branch_name($path, sanitize_text_field(wp_unslash($_POST['delete_branch'] ?? '')));

        if (!$branch) {
            return;
        }

        if (!git_branch_exists($path, $branch)) {
            git_plugin_add_notice('missing_branch', 'Selected branch does not exist.');
            return;
        }

        if (!git_is_repo_clean($path)) {
            git_plugin_add_notice('dirty_repo', 'Commit changes before deleting a branch.');
            return;
        }

        $current_branch = git_get_current_branch($path);

        if ($branch === $current_branch) {
            git_plugin_add_notice('current_branch', 'Cannot delete the active branch.');
            return;
        }

        $default_branch = git_get_default_branch($path);

        if ($default_branch !== '' && $branch === $default_branch) {
            git_plugin_add_notice('protected_branch', 'Cannot delete the default branch.');
            return;
        }

        $result = run_git_command($path, 'branch -d ' . escapeshellarg($branch));

        git_plugin_add_notice(
            'delete_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );

        if ($result['status'] !== 0 || empty($_POST['delete_remote'])) {
            return;
        }

        $remote_name = git_get_primary_remote($path);

        if ($remote_name === '') {
            git_plugin_add_notice('no_remote', 'No remote found for deletion.');
            return;
        }

        $remote_result = run_git_command(
            $path,
            'push ' . escapeshellarg($remote_name) . ' --delete ' . escapeshellarg($branch)
        );

        git_plugin_add_notice(
            'remote_delete',
            implode("\n", $remote_result['output']),
            $remote_result['status'] === 0 ? 'updated' : 'error'
        );
    }
});
