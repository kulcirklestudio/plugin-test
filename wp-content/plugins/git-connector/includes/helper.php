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

function git_get_valid_repo_path()
{
    $path = get_option('git_plugin_local_path');

    if (!$path) {
        add_settings_error('git_plugin', 'no_path', 'No path set');
        return false;
    }

    if (!is_dir($path . '/.git')) {
        add_settings_error('git_plugin', 'invalid_repo', 'Invalid Git repository');
        return false;
    }

    return $path;
}

function git_is_repo_clean($path)
{
    $status = run_git_command($path, 'status --porcelain');
    return empty($status['output']);
}

function git_has_remote($path)
{
    $remote = run_git_command($path, 'remote');
    return !empty($remote['output']);
}

function git_get_default_branch($path)
{
    $result = run_git_command($path, 'symbolic-ref refs/remotes/origin/HEAD');

    if (!empty($result['output'][0])) {
        $ref = $result['output'][0];

        return basename($ref);
    }

    return 'main'; // fallback (safe guess)
}