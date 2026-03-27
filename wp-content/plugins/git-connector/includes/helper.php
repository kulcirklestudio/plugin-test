<?php
function run_git_command($path, $command)
{
    $output = [];
    $status = 0;

    exec('git -C ' . escapeshellarg($path) . ' ' . $command . ' 2>&1', $output, $status);

    return [
        'output' => $output,
        'status' => $status,
    ];
}

function git_plugin_add_notice($code, $message, $type = 'error')
{
    add_settings_error('git_plugin', $code, $message, $type);
}

function git_plugin_get_saved_local_path()
{
    return trim((string) get_option('git_plugin_local_path', ''));
}

function git_plugin_get_saved_remote_url()
{
    return trim((string) get_option('git_plugin_remote_url', ''));
}

function git_plugin_resolve_local_path($path)
{
    $path = trim((string) $path);

    if ($path === '') {
        return false;
    }

    $real = realpath($path);

    if ($real === false || !is_dir($real)) {
        return false;
    }

    return $real;
}

function git_plugin_validate_local_path($path)
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    $real = git_plugin_resolve_local_path($path);

    if ($real === false) {
        add_settings_error(
            'git_plugin_local_path',
            'invalid_path',
            'Invalid path or folder does not exist.'
        );

        return git_plugin_get_saved_local_path();
    }

    return $real;
}

function git_plugin_validate_remote_url($url)
{
    $url = trim((string) $url);

    if ($url === '') {
        return '';
    }

    if (preg_match('/[\r\n\x00]/', $url)) {
        add_settings_error(
            'git_plugin_remote_url',
            'invalid_remote_url',
            'Remote URL contains unsupported characters.'
        );

        return git_plugin_get_saved_remote_url();
    }

    $is_standard_url = preg_match('/^(https?|ssh|git|file):\/\//i', $url);
    $is_scp_style = preg_match('/^[^@\s]+@[^:\s]+:.+$/', $url);
    $is_local_path = preg_match('/^(\/|[A-Za-z]:[\\\\\/]).+$/', $url);

    if (!$is_standard_url && !$is_scp_style && !$is_local_path) {
        add_settings_error(
            'git_plugin_remote_url',
            'invalid_remote_format',
            'Enter a valid Git remote URL. Examples: https://..., git@host:repo.git, or a local bare repo path.'
        );

        return git_plugin_get_saved_remote_url();
    }

    return $url;
}

function git_plugin_get_configured_local_path()
{
    $path = git_plugin_get_saved_local_path();

    if ($path === '') {
        git_plugin_add_notice('no_path', 'Set a local repository path first.');
        return false;
    }

    $real = git_plugin_resolve_local_path($path);

    if ($real === false) {
        git_plugin_add_notice('invalid_path', 'The saved local path no longer exists.');
        return false;
    }

    return $real;
}

function git_is_git_repository($path)
{
    $real = git_plugin_resolve_local_path($path);

    if ($real === false) {
        return false;
    }

    return is_dir($real . DIRECTORY_SEPARATOR . '.git');
}

function git_get_valid_repo_path()
{
    $path = git_plugin_get_configured_local_path();

    if ($path === false) {
        return false;
    }

    if (!git_is_git_repository($path)) {
        git_plugin_add_notice('invalid_repo', 'The selected local path is not a Git repository yet. Use Connect Remote to initialize it first.');
        return false;
    }

    return $path;
}

function git_is_repo_clean($path)
{
    $status = run_git_command($path, 'status --porcelain');

    return $status['status'] === 0 && empty($status['output']);
}

function git_get_remote_names($path)
{
    $remote = run_git_command($path, 'remote');

    if ($remote['status'] !== 0) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $remote['output'])));
}

function git_has_remote($path)
{
    return !empty(git_get_remote_names($path));
}

function git_get_primary_remote($path)
{
    $remotes = git_get_remote_names($path);

    if (in_array('origin', $remotes, true)) {
        return 'origin';
    }

    return $remotes[0] ?? '';
}

function git_is_safe_remote_name($remote_name)
{
    return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $remote_name);
}

function git_get_remote_url($path, $remote_name)
{
    if ($remote_name === '' || !git_is_safe_remote_name($remote_name)) {
        return '';
    }

    $result = run_git_command($path, 'config --get remote.' . $remote_name . '.url');

    if ($result['status'] !== 0) {
        return '';
    }

    return trim($result['output'][0] ?? '');
}

function git_find_remote_by_url($path, $url)
{
    foreach (git_get_remote_names($path) as $remote_name) {
        if (git_get_remote_url($path, $remote_name) === $url) {
            return $remote_name;
        }
    }

    return '';
}

function git_list_local_branches($path)
{
    $branches = run_git_command($path, 'branch --format="%(refname:short)"');

    if ($branches['status'] !== 0) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $branches['output'])));
}

function git_branch_exists($path, $branch)
{
    return in_array($branch, git_list_local_branches($path), true);
}

function git_validate_branch_name($path, $branch)
{
    $branch = trim((string) $branch);

    if ($branch === '') {
        git_plugin_add_notice('invalid_branch', 'Branch name is required.');
        return false;
    }

    $result = run_git_command($path, 'check-ref-format --branch ' . escapeshellarg($branch));

    if ($result['status'] !== 0) {
        git_plugin_add_notice('invalid_branch', 'Invalid branch name.');
        return false;
    }

    return $branch;
}

function git_get_current_branch($path)
{
    $branch = run_git_command($path, 'branch --show-current');

    if ($branch['status'] !== 0) {
        return '';
    }

    return trim($branch['output'][0] ?? '');
}

function git_get_default_branch($path, $remote_name = '')
{
    $remote_name = $remote_name !== '' ? $remote_name : git_get_primary_remote($path);

    if ($remote_name !== '' && git_is_safe_remote_name($remote_name)) {
        $result = run_git_command($path, 'symbolic-ref refs/remotes/' . $remote_name . '/HEAD');

        if ($result['status'] === 0 && !empty($result['output'][0])) {
            return basename(trim($result['output'][0]));
        }
    }

    $current_branch = git_get_current_branch($path);

    if ($current_branch !== '') {
        return $current_branch;
    }

    foreach (['main', 'master'] as $candidate) {
        if (git_branch_exists($path, $candidate)) {
            return $candidate;
        }
    }

    return '';
}

function git_directory_has_non_git_files($path)
{
    $items = scandir($path);

    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.git') {
            continue;
        }

        return true;
    }

    return false;
}
