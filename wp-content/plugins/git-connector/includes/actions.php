<?php
add_action('admin_init', function () {

    if (!current_user_can('manage_options')) {
        return;
    }

    // ======================
    // COMMIT
    // ======================
    if (
        isset($_POST['git_commit']) &&
        wp_verify_nonce($_POST['git_commit_nonce'], 'git_commit_action')
    ) {

        $path = git_get_valid_repo_path();
        $message = sanitize_text_field($_POST['commit_message']);

        if (!$path || !$message)
            return;

        if (!git_is_repo_clean($path)) {
            run_git_command($path, 'add .');
        } else {
            add_settings_error('git_plugin', 'no_changes', 'No changes to commit');
            return;
        }

        $result = run_git_command($path, 'commit -m ' . escapeshellarg($message));

        add_settings_error(
            'git_plugin',
            'commit_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );
    }

    // ======================
    // PULL
    // ======================
    if (
        isset($_POST['git_pull']) &&
        wp_verify_nonce($_POST['git_pull_nonce'], 'git_pull_action')
    ) {

        $path = git_get_valid_repo_path();
        if (!$path)
            return;

        if (!git_is_repo_clean($path)) {
            add_settings_error('git_plugin', 'dirty_repo', 'Commit changes before pulling');
            return;
        }

        if (!git_has_remote($path)) {
            add_settings_error('git_plugin', 'no_remote', 'No remote repository connected');
            return;
        }

        $result = run_git_command($path, 'pull');

        add_settings_error('git_plugin', 'pull_result', implode("\n", $result['output']), $result['status'] === 0 ? 'updated' : 'error');
    }

    // ======================
    // PUSH
    // ======================
    if (
        isset($_POST['git_push']) &&
        wp_verify_nonce($_POST['git_push_nonce'], 'git_push_action')
    ) {

        $path = git_get_valid_repo_path();
        if (!$path)
            return;

        if (!git_is_repo_clean($path)) {
            add_settings_error('git_plugin', 'dirty_repo', 'Commit changes before pushing');
            return;
        }

        if (!git_has_remote($path)) {
            add_settings_error('git_plugin', 'no_remote', 'No remote repository connected');
            return;
        }

        // Get current branch
        $current_branch = run_git_command($path, 'branch --show-current');
        $branch = $current_branch['output'][0] ?? '';

        if (!$branch) {
            add_settings_error('git_plugin', 'no_branch', 'Could not detect current branch');
            return;
        }

        // Check if upstream exists
        $upstream_check = run_git_command($path, 'rev-parse --abbrev-ref --symbolic-full-name @{u}');

        if ($upstream_check['status'] !== 0) {
            // First push (no upstream)
            $result = run_git_command($path, 'push -u origin ' . escapeshellarg($branch));
        } else {
            // Normal push
            $result = run_git_command($path, 'push');
        }

        add_settings_error('git_plugin', 'push_result', implode("\n", $result['output']), $result['status'] === 0 ? 'updated' : 'error');
    }

    // ======================
    // CREATE BRANCH
    // ======================
    if (
        isset($_POST['git_create_branch']) &&
        wp_verify_nonce($_POST['git_create_branch_nonce'], 'git_create_branch_action')
    ) {

        $path = git_get_valid_repo_path();
        $branch = sanitize_text_field($_POST['new_branch']);

        if (!$path || !$branch)
            return;

        if (!git_is_repo_clean($path)) {
            add_settings_error('git_plugin', 'dirty_repo', 'Commit before creating branch');
            return;
        }

        $branches = run_git_command($path, 'branch --format="%(refname:short)"');

        if (in_array($branch, $branches['output'])) {
            add_settings_error('git_plugin', 'branch_exists', 'Branch already exists');
            return;
        }

        $result = run_git_command($path, 'checkout -b ' . escapeshellarg($branch));

        add_settings_error('git_plugin', 'branch_result', implode("\n", $result['output']), $result['status'] === 0 ? 'updated' : 'error');
    }

    // ======================
    // MERGE BRANCH
    // ======================
    if (
        isset($_POST['git_merge_branch']) &&
        isset($_POST['git_merge_branch_nonce']) &&
        wp_verify_nonce($_POST['git_merge_branch_nonce'], 'git_merge_branch_action')
    ) {

        $path = git_get_valid_repo_path();
        $branch = isset($_POST['merge_branch']) ? sanitize_text_field($_POST['merge_branch']) : '';

        if (!$path || !$branch)
            return;

        if (!git_is_repo_clean($path)) {
            add_settings_error('git_plugin', 'dirty_repo', 'Commit changes before merging');
            return;
        }

        $result = run_git_command($path, 'merge ' . escapeshellarg($branch));

        add_settings_error(
            'git_plugin',
            'merge_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );
    }

    // ======================
    // DELETE BRANCH
    // ======================
    if (
        isset($_POST['git_delete_branch']) &&
        isset($_POST['git_delete_branch_nonce']) &&
        wp_verify_nonce($_POST['git_delete_branch_nonce'], 'git_delete_branch_action')
    ) {

        $path = git_get_valid_repo_path();
        $branch = isset($_POST['delete_branch']) ? sanitize_text_field($_POST['delete_branch']) : '';

        if (!$path || !$branch)
            return;

        // 🔴 Block if dirty repo
        if (!git_is_repo_clean($path)) {
            add_settings_error('git_plugin', 'dirty_repo', 'Commit changes before deleting a branch');
            return;
        }

        // 🔴 Block active branch
        $current = run_git_command($path, 'branch --show-current');
        $current_branch = $current['output'][0] ?? '';

        if ($branch === $current_branch) {
            add_settings_error('git_plugin', 'current_branch', 'Cannot delete active branch');
            return;
        }

        // 🔴 Block default branch (if known)
        $default_branch = git_get_default_branch($path);

        if ($default_branch && $branch === $default_branch) {
            add_settings_error('git_plugin', 'protected_branch', 'Cannot delete default branch');
            return;
        }

        // ✅ Safe delete
        $result = run_git_command($path, 'branch -d ' . escapeshellarg($branch));

        add_settings_error(
            'git_plugin',
            'delete_result',
            implode("\n", $result['output']),
            $result['status'] === 0 ? 'updated' : 'error'
        );

        $delete_remote = isset($_POST['delete_remote']);

        if ($delete_remote) {

            if (!git_has_remote($path)) {
                add_settings_error('git_plugin', 'no_remote', 'No remote found for deletion');
                return;
            }

            $remote_result = run_git_command(
                $path,
                'push origin --delete ' . escapeshellarg($branch)
            );

            add_settings_error(
                'git_plugin',
                'remote_delete',
                implode("\n", $remote_result['output']),
                $remote_result['status'] === 0 ? 'updated' : 'error'
            );
        }
    }

});