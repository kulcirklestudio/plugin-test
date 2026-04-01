<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WordPress_Git_Connector
{
    private const OPTION_KEY = 'wgc_settings';
    private const LOG_OPTION_KEY = 'wgc_last_log';
    private const NOTICE_TRANSIENT = 'wgc_admin_notice';
    private const MENU_SLUG = 'wordpress-git-connector';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_wgc_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_wgc_git_action', [$this, 'handle_git_action']);
        add_action('admin_post_wgc_download_backup', [$this, 'handle_backup_download']);
        add_action('admin_post_wgc_export_activity', [$this, 'handle_activity_export']);
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style(
            'wgc-admin',
            plugin_dir_url(dirname(__FILE__)) . 'assets/admin.css',
            [],
            '1.3.0'
        );

        wp_enqueue_script(
            'wgc-admin-js',
            plugin_dir_url(dirname(__FILE__)) . 'assets/admin.js',
            [],
            '1.3.0',
            true
        );
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Git Connector', 'wordpress-git-connector'),
            __('Git Connector', 'wordpress-git-connector'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page'],
            'dashicons-randomize',
            80
        );
    }

    public function register_settings(): void
    {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => $this->default_settings(),
        ]);
    }

    public function sanitize_settings($input): array
    {
        $defaults = $this->default_settings();
        $input = is_array($input) ? $input : [];

        return [
            'git_binary' => isset($input['git_binary']) ? $this->sanitize_path($input['git_binary']) : '',
            'repo_mode' => isset($input['repo_mode']) && in_array($input['repo_mode'], ['existing', 'clone'], true) ? $input['repo_mode'] : $defaults['repo_mode'],
            'local_path' => isset($input['local_path']) ? $this->sanitize_path($input['local_path']) : '',
            'clone_parent' => isset($input['clone_parent']) ? $this->sanitize_path($input['clone_parent']) : '',
            'remote_url' => isset($input['remote_url']) ? sanitize_text_field($input['remote_url']) : '',
            'ssh_key_path' => isset($input['ssh_key_path']) ? $this->sanitize_path($input['ssh_key_path']) : '',
            'default_branch' => isset($input['default_branch']) ? sanitize_text_field($input['default_branch']) : 'main',
            'author_name' => isset($input['author_name']) ? sanitize_text_field($input['author_name']) : '',
            'author_email' => isset($input['author_email']) ? sanitize_email($input['author_email']) : '',
            'allow_direct_main_changes' => !empty($input['allow_direct_main_changes']) ? '1' : '0',
        ];
    }

    public function handle_save_settings(): void
    {
        $this->ensure_access();
        check_admin_referer('wgc_save_settings');

        $settings = $this->sanitize_settings($_POST[self::OPTION_KEY] ?? []);
        if ($settings['git_binary'] === '' || strtolower($settings['git_binary']) === 'git') {
            $detectedGit = $this->detect_git_binary();
            if ($detectedGit !== '') {
                $settings['git_binary'] = $detectedGit;
            }
        }
        update_option(self::OPTION_KEY, array_merge($this->default_settings(), $settings));
        $this->record_activity('save_settings', [
            'success' => true,
            'message' => __('Settings saved.', 'wordpress-git-connector'),
            'output' => '',
        ], $settings);

        $this->set_notice('success', __('Settings saved.', 'wordpress-git-connector'));
        $this->redirect_back();
    }

    public function handle_git_action(): void
    {
        $this->ensure_access();
        check_admin_referer('wgc_git_action');

        $action = isset($_POST['wgc_action']) ? sanitize_key(wp_unslash($_POST['wgc_action'])) : '';
        $settings = $this->get_settings();
        $result = null;
        $startedAt = microtime(true);

        switch ($action) {
            case 'save_gitignore':
                $contents = isset($_POST['gitignore_contents']) ? (string) wp_unslash($_POST['gitignore_contents']) : '';
                $result = $this->save_gitignore($settings, $contents);
                break;
            case 'apply_gitignore_suggestions':
                $result = $this->apply_gitignore_suggestions($settings);
                break;
            case 'run_diagnostics':
                $result = $this->run_diagnostics_action($settings);
                break;
            case 'initialize_repo':
                $result = $this->initialize_repo($settings);
                break;
            case 'test_connection':
                $result = $this->test_connection($settings);
                break;
            case 'test_remote':
                $result = $this->guard_remote_configuration($settings);
                if ($result === null) {
                    $result = $this->run_git('ls-remote --heads origin', $settings, null, __('Remote connection test completed successfully.', 'wordpress-git-connector'));
                }
                break;
            case 'clone_repo':
                $result = $this->clone_repo($settings);
                break;
            case 'connect_repo':
                $result = $this->validate_repo($settings);
                break;
            case 'fetch':
                $result = $this->guard_remote_configuration($settings);
                if ($result === null) {
                    $result = $this->run_git('fetch --all --prune', $settings);
                }
                break;
            case 'sync_remote_branches':
                $result = $this->guard_remote_configuration($settings);
                if ($result === null) {
                    $result = $this->sync_remote_branches($settings);
                }
                break;
            case 'pull':
                $result = $this->guard_remote_configuration($settings);
                if ($result === null) {
                    $result = $this->run_git('pull --rebase', $settings);
                }
                break;
            case 'push':
                $result = $this->guard_remote_configuration($settings);
                if ($result === null) {
                    $result = $this->guard_protected_branch_action($settings, 'push');
                }
                if ($result === null) {
                    $result = $this->guard_uncommitted_changes_before_push($settings);
                }
                if ($result === null) {
                    $result = $this->push_with_upstream($settings);
                }
                break;
            case 'status':
                $result = $this->run_git('status --short --branch', $settings);
                break;
            case 'add_all':
                $result = $this->stage_all_changes($settings);
                break;
            case 'commit':
                $message = isset($_POST['commit_message']) ? sanitize_textarea_field(wp_unslash($_POST['commit_message'])) : '';
                $result = $this->guard_protected_branch_action($settings, 'commit');
                if ($result === null) {
                    $result = $this->commit_changes($settings, $message);
                }
                break;
            case 'set_remote':
                $remoteUrl = isset($_POST['remote_url']) ? sanitize_text_field(wp_unslash($_POST['remote_url'])) : '';
                $result = $this->set_remote($settings, $remoteUrl);
                break;
            case 'create_branch':
                $branchName = isset($_POST['branch_name']) ? sanitize_text_field(wp_unslash($_POST['branch_name'])) : '';
                $result = $this->run_git('checkout -b ' . escapeshellarg($branchName), $settings);
                break;
            case 'merge_into_active':
                $sourceBranch = isset($_POST['source_branch']) ? sanitize_text_field(wp_unslash($_POST['source_branch'])) : '';
                $result = $this->merge_into_active_branch($settings, $sourceBranch);
                break;
            case 'checkout_branch':
                $branchName = isset($_POST['active_branch']) ? sanitize_text_field(wp_unslash($_POST['active_branch'])) : '';
                $result = $this->run_git('checkout ' . escapeshellarg($branchName), $settings);
                break;
            case 'delete_branch':
                $branchName = isset($_POST['branch_name']) ? sanitize_text_field(wp_unslash($_POST['branch_name'])) : '';
                $deleteRemote = !empty($_POST['delete_remote_branch']);
                $createBackup = !empty($_POST['create_backup_branch']);
                $createBackupFile = !empty($_POST['create_backup_file']);
                $result = $this->delete_branch($settings, $branchName, $deleteRemote, $createBackup, $createBackupFile);
                break;
            default:
                $this->set_notice('error', __('Unknown Git action.', 'wordpress-git-connector'));
                $this->redirect_back();
        }

        if ($result && !empty($result['success'])) {
            $this->set_notice('success', $result['message'], $result['output'] ?? '');
        } else {
            $message = $result['message'] ?? __('Git action failed.', 'wordpress-git-connector');
            $output = $result['output'] ?? '';
            $this->set_notice('error', $message, $output);
        }

        $this->record_activity($action, $result ?? [
            'success' => false,
            'message' => __('Git action failed.', 'wordpress-git-connector'),
            'output' => '',
        ], $settings, (microtime(true) - $startedAt));

        $this->redirect_back();
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $notice = $this->get_notice();
        $repoInfo = $this->get_repo_info($settings);
        $uiState = $this->get_ui_state($settings, $repoInfo);
        $diagnostics = $this->get_diagnostics_report($settings);
        $gitignoreContents = $this->get_gitignore_contents($settings);
        $statusFilter = isset($_GET['wgc_log_status']) ? sanitize_key(wp_unslash($_GET['wgc_log_status'])) : 'all';
        $actionFilter = isset($_GET['wgc_log_action']) ? sanitize_key(wp_unslash($_GET['wgc_log_action'])) : 'all';
        $activityLog = $this->get_filtered_activity_log($statusFilter, $actionFilter);
        $activityActions = $this->get_activity_actions();

        require $this->get_view_path('admin-page.php');
    }

    private function get_view_path(string $view): string
    {
        return plugin_dir_path(dirname(__FILE__)) . 'includes/views/' . ltrim($view, '/');
    }

    private function render_action_card(string $title, string $description, string $eyebrow, callable $callback): void
    {
        ?>
        <div class="wgc-panel wgc-action-card">
            <div class="wgc-card-eyebrow"><?php echo esc_html($eyebrow); ?></div>
            <h3><?php echo esc_html($title); ?></h3>
            <p class="description wgc-card-description"><?php echo esc_html($description); ?></p>
            <?php $callback(); ?>
        </div>
        <?php
    }

    private function render_action_form(string $action, string $label): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-action-form">
            <?php wp_nonce_field('wgc_git_action'); ?>
            <input type="hidden" name="action" value="wgc_git_action">
            <input type="hidden" name="wgc_action" value="<?php echo esc_attr($action); ?>">
            <?php submit_button($label, 'secondary wgc-secondary-button', '', false); ?>
        </form>
        <?php
    }

    private function render_action_button_group(array $actions): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-action-grid-form">
            <?php wp_nonce_field('wgc_git_action'); ?>
            <input type="hidden" name="action" value="wgc_git_action">
            <div class="wgc-button-grid">
                <?php foreach ($actions as $action) : ?>
                    <button
                        type="submit"
                        class="button button-secondary wgc-secondary-button <?php echo !empty($action['disabled']) ? 'is-disabled' : ''; ?>"
                        name="wgc_action"
                        value="<?php echo esc_attr($action['action']); ?>"
                        <?php disabled(!empty($action['disabled'])); ?>
                        <?php if (!empty($action['confirm'])) : ?>data-confirm="<?php echo esc_attr($action['confirm']); ?>"<?php endif; ?>
                        <?php if (!empty($action['disabled_reason'])) : ?>title="<?php echo esc_attr($action['disabled_reason']); ?>"<?php endif; ?>
                    >
                        <?php echo esc_html($action['label']); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </form>
        <?php
    }

    private function render_branch_options(array $branches, string $mainBranch, string $selected = '', string $disabledBranch = ''): void
    {
        foreach ($branches as $branch) {
            if ($disabledBranch !== '' && $branch === $disabledBranch) {
                continue;
            }
            ?>
            <option value="<?php echo esc_attr($branch); ?>" <?php selected($selected, $branch); ?>>
                <?php echo esc_html($branch === $mainBranch ? $branch . ' (main branch)' : $branch); ?>
            </option>
            <?php
        }
    }

    private function render_submit_button(string $label, array $options = []): void
    {
        $classes = $options['class'] ?? 'secondary';
        $attributes = [];

        if (!empty($options['disabled'])) {
            $attributes['disabled'] = 'disabled';
        }
        if (!empty($options['confirm'])) {
            $attributes['data-confirm'] = $options['confirm'];
        }

        submit_button($label, $classes, '', false, $attributes);
    }

    private function render_remote_update_form(string $remoteUrl): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-stack-form">
            <?php wp_nonce_field('wgc_git_action'); ?>
            <input type="hidden" name="action" value="wgc_git_action">
            <input type="hidden" name="wgc_action" value="set_remote">
            <p>
                <label for="wgc_remote_update"><strong><?php esc_html_e('Update Remote URL', 'wordpress-git-connector'); ?></strong></label><br>
                <input id="wgc_remote_update" name="remote_url" type="text" class="regular-text code" value="<?php echo esc_attr($remoteUrl); ?>">
            </p>
            <?php submit_button(__('Save Remote', 'wordpress-git-connector'), 'secondary', '', false); ?>
        </form>
        <?php
    }

    private function default_settings(): array
    {
        return [
            'git_binary' => 'git',
            'repo_mode' => 'existing',
            'local_path' => '',
            'clone_parent' => '',
            'remote_url' => '',
            'ssh_key_path' => '',
            'default_branch' => 'main',
            'author_name' => '',
            'author_email' => '',
            'allow_direct_main_changes' => '0',
        ];
    }

    private function get_settings(): array
    {
        $settings = array_merge($this->default_settings(), get_option(self::OPTION_KEY, []));

        if ($settings['git_binary'] === '' || strtolower($settings['git_binary']) === 'git') {
            $detectedGit = $this->detect_git_binary();
            if ($detectedGit !== '') {
                $settings['git_binary'] = $detectedGit;
            }
        }

        return $settings;
    }

    private function ensure_access(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage this plugin.', 'wordpress-git-connector'));
        }
    }

    private function sanitize_path(string $path): string
    {
        return trim($path);
    }

    private function detect_git_binary(): string
    {
        $output = [];
        $exitCode = 1;

        // Detect OS
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        // 1. Try system PATH first (most reliable)
        if ($isWindows) {
            $command = 'where git 2>NUL';
        } else {
            $command = 'which git 2>/dev/null';
        }

        exec($command, $output, $exitCode);

        if ($exitCode === 0 && !empty($output)) {
            foreach ($output as $path) {
                $path = trim($path);
                if ($path !== '' && is_executable($path)) {
                    return $path;
                }
            }
        }

        // 2. Fallback common install paths
        $candidates = $isWindows
            ? [
                'C:\\Program Files\\Git\\cmd\\git.exe',
                'C:\\Program Files\\Git\\bin\\git.exe',
                'C:\\Program Files (x86)\\Git\\cmd\\git.exe',
                'C:\\Program Files (x86)\\Git\\bin\\git.exe',
            ]
            : [
                '/usr/bin/git',
                '/usr/local/bin/git',
                '/opt/homebrew/bin/git',
            ];

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        // 3. Last fallback: try plain "git" (PATH might still work in proc_open)
        return 'git';
    }

    private function get_ui_state(array $settings, array $repoInfo): array
    {
        $repoPath = trim((string) ($settings['local_path'] ?? ''));
        $pathExists = $repoPath !== '' && is_dir($repoPath);
        $hasRepo = $pathExists && is_dir($repoPath . DIRECTORY_SEPARATOR . '.git');
        $hasRemote = trim((string) ($repoInfo['remote_url'] ?: $settings['remote_url'])) !== '';
        $hasSshKey = trim((string) ($settings['ssh_key_path'] ?? '')) !== '' && file_exists((string) $settings['ssh_key_path']);
        $hasBranches = !empty($repoInfo['branches']);
        $hasMultipleBranches = count($repoInfo['branches']) > 1;
        $deletableBranches = array_values(array_filter($repoInfo['branches'], function (string $branch) use ($repoInfo): bool {
            return $branch !== $repoInfo['active_branch'];
        }));

        $warnings = [];
        if (!$hasRepo) {
            $warnings[] = __('No Git repository is connected yet. Initialize or connect a repository before using Git actions.', 'wordpress-git-connector');
        }
        if (!$hasRemote) {
            $warnings[] = __('Remote SSH URL is missing. Push, pull, fetch, and remote branch import will stay disabled until it is configured.', 'wordpress-git-connector');
        }
        if ($hasRemote && !$hasSshKey) {
            $warnings[] = __('SSH key file is missing or unreadable. Remote Git actions need a valid private key.', 'wordpress-git-connector');
        }
        if (!empty($repoInfo['active_branch']) && $repoInfo['active_branch'] === ($settings['default_branch'] ?? 'main') && ($settings['allow_direct_main_changes'] ?? '0') !== '1') {
            $warnings[] = __('The protected main branch is active. Direct commit and push are blocked until you switch to a working branch or enable direct main changes.', 'wordpress-git-connector');
        }

        return [
            'path_exists' => $pathExists,
            'has_repo' => $hasRepo,
            'has_remote' => $hasRemote,
            'has_ssh_key' => $hasSshKey,
            'can_run_remote' => $hasRepo && $hasRemote && $hasSshKey,
            'can_clone' => $repoPath !== '' && trim((string) ($settings['remote_url'] ?? '')) !== '',
            'has_branches' => $hasBranches,
            'has_multiple_branches' => $hasMultipleBranches,
            'has_deletable_branch' => !empty($deletableBranches),
            'working_tree_dirty' => (($repoInfo['status_counts']['staged'] ?? 0) + ($repoInfo['status_counts']['unstaged'] ?? 0) + ($repoInfo['status_counts']['untracked'] ?? 0)) > 0,
            'warnings' => $warnings,
        ];
    }

    public function handle_activity_export(): void
    {
        $this->ensure_access();
        check_admin_referer('wgc_export_activity');

        $entries = $this->get_filtered_activity_log(
            isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all',
            isset($_GET['action_filter']) ? sanitize_key(wp_unslash($_GET['action_filter'])) : 'all'
        );

        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="git-activity-log-' . gmdate('Ymd-His') . '.txt"');

        foreach ($entries as $entry) {
            echo '[' . ($entry['time'] ?? '') . '] ';
            echo ($entry['title'] ?? 'Git Action') . ' - ';
            echo !empty($entry['success']) ? 'SUCCESS' : 'ERROR';
            echo PHP_EOL . ($entry['message'] ?? '') . PHP_EOL;
            if (!empty($entry['meta'])) {
                echo ($entry['meta']) . PHP_EOL;
            }
            if (!empty($entry['duration_ms'])) {
                echo 'Duration: ' . $entry['duration_ms'] . ' ms' . PHP_EOL;
            }
            if (!empty($entry['output'])) {
                echo $entry['output'] . PHP_EOL;
            }
            echo str_repeat('-', 72) . PHP_EOL;
        }
        exit;
    }

    private function save_gitignore(array $settings, string $contents): array
    {
        $path = $this->get_gitignore_path($settings);
        if ($path === '') {
            return $this->failure(__('.gitignore cannot be edited until a local repo path is configured.', 'wordpress-git-connector'));
        }

        $repoDir = dirname($path);
        if (!is_dir($repoDir) && !wp_mkdir_p($repoDir)) {
            return $this->failure(__('Could not create the local repository directory for .gitignore.', 'wordpress-git-connector'), $repoDir);
        }

        $written = file_put_contents($path, $contents);
        if ($written === false) {
            return $this->failure(__('Could not save .gitignore.', 'wordpress-git-connector'), $path);
        }

        return [
            'success' => true,
            'message' => __('Saved .gitignore successfully.', 'wordpress-git-connector'),
            'output' => $path,
        ];
    }

    private function apply_gitignore_suggestions(array $settings): array
    {
        $path = $this->get_gitignore_path($settings);
        if ($path === '') {
            return $this->failure(__('.gitignore suggestions require a configured local repo path.', 'wordpress-git-connector'));
        }

        $current = file_exists($path) ? (string) file_get_contents($path) : '';
        $currentLines = preg_split('/\r\n|\r|\n/', $current);
        $currentLookup = array_fill_keys(array_filter(array_map('trim', $currentLines)), true);
        $added = [];

        foreach ($this->get_gitignore_suggestions() as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || isset($currentLookup[$trimmed])) {
                continue;
            }
            $current .= ($current !== '' && !str_ends_with($current, "\n") ? PHP_EOL : '') . $line . PHP_EOL;
            $currentLookup[$trimmed] = true;
            $added[] = $line;
        }

        if (!$added) {
            return [
                'success' => true,
                'message' => __('All suggested WordPress exclusions are already present in .gitignore.', 'wordpress-git-connector'),
                'output' => '',
            ];
        }

        return $this->save_gitignore($settings, $current);
    }

    private function run_diagnostics_action(array $settings): array
    {
        $report = $this->get_diagnostics_report($settings, true);
        $lines = [];
        foreach ($report['checks'] as $check) {
            $lines[] = '[' . strtoupper($check['status']) . '] ' . $check['label'] . ': ' . $check['message'];
        }

        return [
            'success' => $report['ready'],
            'message' => $report['ready']
                ? __('Environment readiness report completed successfully.', 'wordpress-git-connector')
                : __('Environment readiness report found issues that should be fixed.', 'wordpress-git-connector'),
            'output' => implode(PHP_EOL, $lines),
        ];
    }

    private function get_gitignore_path(array $settings): string
    {
        $repoPath = trim((string) ($settings['local_path'] ?? ''));
        return $repoPath === '' ? '' : $repoPath . DIRECTORY_SEPARATOR . '.gitignore';
    }

    private function get_gitignore_contents(array $settings): string
    {
        $path = $this->get_gitignore_path($settings);
        return ($path !== '' && file_exists($path)) ? (string) file_get_contents($path) : '';
    }

    private function get_gitignore_suggestions(): array
    {
        return [
            'wp-content/uploads/',
            'wp-content/cache/',
            'wp-content/upgrade/',
            'wp-content/backups/',
            'wp-content/advanced-cache.php',
            '.env',
            '.env.*',
            '*.log',
            '.DS_Store',
            'Thumbs.db',
            '.idea/',
            '.vscode/',
        ];
    }

    private function get_diagnostics_report(array $settings, bool $withHandshake = false): array
    {
        $checks = [];
        $gitBinary = trim((string) ($settings['git_binary'] ?? ''));
        $repoPath = trim((string) ($settings['local_path'] ?? ''));
        $sshKey = trim((string) ($settings['ssh_key_path'] ?? ''));
        $remoteUrl = trim((string) ($settings['remote_url'] ?? ''));

        $checks[] = $this->make_check('Git binary', $gitBinary !== '' && (is_file($gitBinary) || strtolower($gitBinary) === 'git'), $gitBinary !== '' ? $gitBinary : __('Not detected', 'wordpress-git-connector'));

        $sshBinary = $this->detect_ssh_binary();
        $checks[] = $this->make_check('SSH binary', $sshBinary !== '', $sshBinary !== '' ? $sshBinary : __('ssh.exe not found in common locations or PATH.', 'wordpress-git-connector'));

        $checks[] = $this->make_check('Local path exists', $repoPath !== '' && is_dir($repoPath), $repoPath !== '' ? $repoPath : __('Local path not configured.', 'wordpress-git-connector'));
        $checks[] = $this->make_check('Local path writable', $repoPath !== '' && is_dir($repoPath) && is_writable($repoPath), $repoPath !== '' ? __('WordPress can write to the repository directory.', 'wordpress-git-connector') : __('Local path not configured.', 'wordpress-git-connector'));
        $checks[] = $this->make_check('Git repository detected', $repoPath !== '' && is_dir($repoPath . DIRECTORY_SEPARATOR . '.git'), $repoPath !== '' ? __('The .git directory is ' . (is_dir($repoPath . DIRECTORY_SEPARATOR . '.git') ? 'present.' : 'missing.'), 'wordpress-git-connector') : __('Local path not configured.', 'wordpress-git-connector'));
        $checks[] = $this->make_check('SSH remote configured', $remoteUrl !== '' && $this->is_ssh_remote($remoteUrl), $remoteUrl !== '' ? $remoteUrl : __('Remote URL not configured.', 'wordpress-git-connector'));
        $checks[] = $this->make_check('SSH key exists', $sshKey !== '' && file_exists($sshKey), $sshKey !== '' ? $sshKey : __('SSH key path not configured.', 'wordpress-git-connector'));
        $checks[] = $this->make_check('SSH key readable', $sshKey !== '' && is_readable($sshKey), $sshKey !== '' ? __('WordPress can read the SSH key file.', 'wordpress-git-connector') : __('SSH key path not configured.', 'wordpress-git-connector'));

        if ($withHandshake && $sshBinary !== '' && $sshKey !== '' && file_exists($sshKey)) {
            $checks[] = $this->run_github_handshake_check($sshBinary, $sshKey);
        }

        $ready = true;
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $ready = false;
                break;
            }
        }

        return [
            'ready' => $ready,
            'checks' => $checks,
        ];
    }

    private function detect_ssh_binary(): string
    {
        $candidates = [
            'C:\\Windows\\System32\\OpenSSH\\ssh.exe',
            'C:\\Program Files\\Git\\usr\\bin\\ssh.exe',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $output = [];
        $exitCode = 1;
        @exec('where ssh 2>NUL', $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            foreach ($output as $path) {
                $path = trim((string) $path);
                if ($path !== '' && is_file($path)) {
                    return $path;
                }
            }
        }

        return '';
    }

    private function run_github_handshake_check(string $sshBinary, string $sshKey): array
    {
        $command = escapeshellarg($sshBinary) . ' -T -i ' . escapeshellarg($sshKey) . ' -o BatchMode=yes -o StrictHostKeyChecking=accept-new git@github.com';
        $output = [];
        $exitCode = 1;
        @exec($command . ' 2>&1', $output, $exitCode);
        $text = trim(implode(PHP_EOL, $output));
        $success = str_contains(strtolower($text), 'successfully authenticated') || str_contains(strtolower($text), 'hi ');

        return $this->make_check(
            'GitHub SSH handshake',
            $success,
            $text !== '' ? $text : __('No handshake output returned.', 'wordpress-git-connector')
        );
    }

    private function make_check(string $label, bool $ok, string $message): array
    {
        return [
            'label' => $label,
            'status' => $ok ? 'success' : 'error',
            'message' => $message,
        ];
    }

    private function validate_repo(array $settings): array
    {
        $path = $settings['local_path'];

        if ($path === '' || !is_dir($path)) {
            return $this->failure(__('Local repository path does not exist.', 'wordpress-git-connector'));
        }

        if (!is_dir($path . DIRECTORY_SEPARATOR . '.git')) {
            return $this->failure(__('The local path is not a Git repository.', 'wordpress-git-connector'));
        }

        return $this->run_git('status --short --branch', $settings, $path, __('Repository connected successfully.', 'wordpress-git-connector'));
    }

    private function test_connection(array $settings): array
    {
        $path = trim((string) $settings['local_path']);
        if ($path === '') {
            return $this->failure(__('Set a local repo path first.', 'wordpress-git-connector'));
        }

        if (!is_dir($path)) {
            return $this->failure(__('The local repo path does not exist yet. Use Initialize Local Repo to create it.', 'wordpress-git-connector'), $path);
        }

        if (!is_dir($path . DIRECTORY_SEPARATOR . '.git')) {
            return $this->failure(__('No Git repository exists at this path. Use Initialize Local Repo or Connect Existing Repo first.', 'wordpress-git-connector'), $path);
        }

        return $this->run_git('status --short --branch', $settings, $path, __('Repository connection test completed successfully.', 'wordpress-git-connector'));
    }

    private function initialize_repo(array $settings): array
    {
        $path = trim((string) $settings['local_path']);
        if ($path === '') {
            return $this->failure(__('Local repo path is required to initialize a repository.', 'wordpress-git-connector'));
        }

        if (!is_dir($path) && !wp_mkdir_p($path)) {
            return $this->failure(__('Could not create the local repository directory.', 'wordpress-git-connector'), $path);
        }

        $gitDir = $path . DIRECTORY_SEPARATOR . '.git';
        if (!is_dir($gitDir)) {
            $initResult = $this->run_git('init', $settings, $path, __('Repository initialized successfully.', 'wordpress-git-connector'));
            if (empty($initResult['success'])) {
                return $initResult;
            }
        }

        $branchName = trim((string) $settings['default_branch']);
        if ($branchName !== '') {
            $branchResult = $this->run_git('branch -M ' . escapeshellarg($branchName), $settings, $path);
            if (empty($branchResult['success'])) {
                return $branchResult;
            }
        }

        $ignoreResult = $this->ensure_gitignore($path);
        if (empty($ignoreResult['success'])) {
            return $ignoreResult;
        }

        if (($settings['remote_url'] ?? '') !== '') {
            $remoteResult = $this->set_remote($settings, $settings['remote_url']);
            if (empty($remoteResult['success'])) {
                return $remoteResult;
            }
        }

        return [
            'success' => true,
            'message' => __('Local repository bootstrapped successfully.', 'wordpress-git-connector'),
            'output' => implode(PHP_EOL, array_filter([
                'Path: ' . $path,
                is_dir($gitDir) ? '.git directory is ready.' : '',
                file_exists($path . DIRECTORY_SEPARATOR . '.gitignore') ? '.gitignore is ready.' : '',
                ($settings['remote_url'] ?? '') !== '' ? 'Remote configured: ' . $settings['remote_url'] : 'Remote not configured.',
            ])),
        ];
    }

    private function clone_repo(array $settings): array
    {
        if ($settings['remote_url'] === '') {
            return $this->failure(__('SSH remote URL is required for cloning.', 'wordpress-git-connector'));
        }

        if (!$this->is_ssh_remote($settings['remote_url'])) {
            return $this->failure(__('Clone requires an SSH remote URL such as git@github.com:owner/repo.git.', 'wordpress-git-connector'));
        }

        if ($settings['local_path'] === '') {
            return $this->failure(__('Local repo path is required for cloning.', 'wordpress-git-connector'));
        }

        $parent = $settings['clone_parent'] ?: dirname($settings['local_path']);
        if ($parent === '' || !is_dir($parent)) {
            return $this->failure(__('Clone parent path does not exist.', 'wordpress-git-connector'));
        }

        if (is_dir($settings['local_path']) && is_dir($settings['local_path'] . DIRECTORY_SEPARATOR . '.git')) {
            return $this->failure(__('Target path already contains a Git repository.', 'wordpress-git-connector'));
        }

        $repoName = basename($settings['local_path']);
        $command = 'clone --branch ' . escapeshellarg($settings['default_branch']) . ' ' . escapeshellarg($settings['remote_url']) . ' ' . escapeshellarg($repoName);

        return $this->run_git($command, $settings, $parent, __('Repository cloned successfully.', 'wordpress-git-connector'));
    }

    private function commit_changes(array $settings, string $message): array
    {
        if ($message === '') {
            return $this->failure(__('Commit message is required.', 'wordpress-git-connector'));
        }

        if ($settings['author_name'] !== '') {
            $result = $this->run_git('config user.name ' . escapeshellarg($settings['author_name']), $settings);
            if (empty($result['success'])) {
                return $result;
            }
        }

        if ($settings['author_email'] !== '') {
            $result = $this->run_git('config user.email ' . escapeshellarg($settings['author_email']), $settings);
            if (empty($result['success'])) {
                return $result;
            }
        }

        $commitResult = $this->run_git('commit -m ' . escapeshellarg($message), $settings, null, __('Commit created successfully.', 'wordpress-git-connector'));
        if (empty($commitResult['success'])) {
            return $commitResult;
        }

        $hashResult = $this->run_git('rev-parse --short HEAD', $settings);
        if (!empty($hashResult['success'])) {
            $hash = trim((string) $hashResult['output']);
            $commitResult['output'] = trim($commitResult['output'] . PHP_EOL . 'Commit: ' . $hash);
        }

        return $commitResult;
    }

    private function stage_all_changes(array $settings): array
    {
        $stageResult = $this->run_git(
            '-c core.autocrlf=false -c core.safecrlf=false add -A',
            $settings,
            null,
            __('All changes staged successfully.', 'wordpress-git-connector')
        );

        if (empty($stageResult['success'])) {
            return $stageResult;
        }

        $statusResult = $this->run_git('status --short', $settings);
        if (empty($statusResult['success'])) {
            return $statusResult;
        }

        $statusOutput = trim((string) $statusResult['output']);
        if ($statusOutput === '') {
            $stageResult['output'] = __('No file changes are currently staged.', 'wordpress-git-connector');
            return $stageResult;
        }

        $stageResult['output'] = $this->format_status_summary($statusOutput);
        return $stageResult;
    }

    private function sync_remote_branches(array $settings): array
    {
        $fetchResult = $this->run_git('fetch --all --prune', $settings);
        if (empty($fetchResult['success'])) {
            return $fetchResult;
        }

        $remoteResult = $this->run_git('branch -r', $settings);
        if (empty($remoteResult['success'])) {
            return $remoteResult;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim((string) $remoteResult['output']));
        $created = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, 'origin/HEAD') === 0) {
                continue;
            }

            if (strpos($line, 'origin/') !== 0) {
                continue;
            }

            $branchName = substr($line, strlen('origin/'));
            if ($branchName === '') {
                continue;
            }

            $existsResult = $this->run_git('show-ref --verify --quiet refs/heads/' . escapeshellarg($branchName), $settings);
            if (!empty($existsResult['success'])) {
                continue;
            }

            $createResult = $this->run_git(
                'branch --track ' . escapeshellarg($branchName) . ' ' . escapeshellarg('origin/' . $branchName),
                $settings
            );

            if (empty($createResult['success'])) {
                return $createResult;
            }

            $created[] = $branchName;
        }

        return [
            'success' => true,
            'message' => __('Remote branches imported successfully.', 'wordpress-git-connector'),
            'output' => $created
                ? 'Created local tracking branches: ' . implode(', ', $created)
                : 'All remote branches were already available locally.',
        ];
    }

    private function merge_into_active_branch(array $settings, string $sourceBranch): array
    {
        $sourceBranch = trim($sourceBranch);
        if ($sourceBranch === '') {
            return $this->failure(__('Select a source branch to merge.', 'wordpress-git-connector'));
        }

        $activeBranch = $this->get_active_branch_name($settings);
        if ($activeBranch === '') {
            return $this->failure(__('Could not detect the active branch for merge.', 'wordpress-git-connector'));
        }

        if ($activeBranch === $sourceBranch) {
            return $this->failure(__('Choose a different branch. A branch cannot be merged into itself.', 'wordpress-git-connector'));
        }

        return $this->run_git(
            'merge --no-edit ' . escapeshellarg($sourceBranch),
            $settings,
            null,
            sprintf(
                __('Merged %1$s into %2$s successfully.', 'wordpress-git-connector'),
                $sourceBranch,
                $activeBranch
            )
        );
    }

    private function delete_branch(array $settings, string $branchName, bool $deleteRemote, bool $createBackup, bool $createBackupFile): array
    {
        $branchName = trim($branchName);
        if ($branchName === '') {
            return $this->failure(__('Enter a branch name to delete.', 'wordpress-git-connector'));
        }

        $mainBranch = trim((string) ($settings['default_branch'] ?? 'main'));
        if ($mainBranch !== '' && $branchName === $mainBranch) {
            return $this->failure(__('The configured main branch cannot be deleted from this plugin.', 'wordpress-git-connector'));
        }

        $activeBranch = $this->get_active_branch_name($settings);
        if ($activeBranch !== '' && $activeBranch === $branchName) {
            return $this->failure(__('Switch to a different branch before deleting the active branch.', 'wordpress-git-connector'));
        }

        $existsResult = $this->run_git('show-ref --verify --quiet refs/heads/' . escapeshellarg($branchName), $settings);
        if (empty($existsResult['success'])) {
            return $this->failure(__('The local branch does not exist.', 'wordpress-git-connector'), $branchName);
        }

        $messages = [];

        if ($createBackupFile) {
            $backupFileResult = $this->create_backup_file($settings, $branchName);
            if (empty($backupFileResult['success'])) {
                return $backupFileResult;
            }

            $messages[] = 'Backup file created: ' . $backupFileResult['output'];
        }

        if ($createBackup) {
            $backupBranch = 'backup/' . $branchName . '-' . gmdate('Ymd-His');
            $backupResult = $this->run_git(
                'branch ' . escapeshellarg($backupBranch) . ' ' . escapeshellarg($branchName),
                $settings,
                null,
                __('Backup branch created.', 'wordpress-git-connector')
            );

            if (empty($backupResult['success'])) {
                return $backupResult;
            }

            $messages[] = 'Backup created: ' . $backupBranch;
        }

        $deleteLocalResult = $this->run_git(
            'branch -D ' . escapeshellarg($branchName),
            $settings,
            null,
            __('Local branch deleted.', 'wordpress-git-connector')
        );
        if (empty($deleteLocalResult['success'])) {
            return $deleteLocalResult;
        }

        $messages[] = 'Local branch deleted: ' . $branchName;

        if ($deleteRemote) {
            $remoteGuard = $this->guard_remote_configuration($settings);
            if ($remoteGuard !== null) {
                return $remoteGuard;
            }

            $deleteRemoteResult = $this->run_git(
                'push origin --delete ' . escapeshellarg($branchName),
                $settings,
                null,
                __('Remote branch deleted.', 'wordpress-git-connector')
            );
            if (empty($deleteRemoteResult['success'])) {
                return $deleteRemoteResult;
            }

            $messages[] = 'Remote branch deleted: origin/' . $branchName;
        }

        return [
            'success' => true,
            'message' => __('Branch deletion completed successfully.', 'wordpress-git-connector'),
            'output' => implode(PHP_EOL, $messages),
        ];
    }

    private function create_backup_file(array $settings, string $branchName): array
    {
        $repoPath = rtrim((string) $settings['local_path'], DIRECTORY_SEPARATOR);
        if ($repoPath === '') {
            return $this->failure(__('Local repo path is required to create a backup file.', 'wordpress-git-connector'));
        }

        $backupDir = $repoPath . DIRECTORY_SEPARATOR . '.git-branch-backups';
        if (!is_dir($backupDir) && !wp_mkdir_p($backupDir)) {
            return $this->failure(__('Could not create the backup directory.', 'wordpress-git-connector'), $backupDir);
        }

        $safeBranch = preg_replace('/[^A-Za-z0-9._-]+/', '-', $branchName);
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . $safeBranch . '-' . gmdate('Ymd-His') . '.patch';

        $result = $this->run_git(
            'format-patch --stdout ' . escapeshellarg($settings['default_branch'] ?: 'main') . '..' . escapeshellarg($branchName),
            $settings
        );
        if (empty($result['success'])) {
            return $result;
        }

        $written = file_put_contents($backupFile, (string) $result['output']);
        if ($written === false) {
            return $this->failure(__('Could not write the backup patch file.', 'wordpress-git-connector'), $backupFile);
        }

        return [
            'success' => true,
            'message' => __('Backup file created successfully.', 'wordpress-git-connector'),
            'output' => $backupFile,
        ];
    }

    private function guard_protected_branch_action(array $settings, string $action): ?array
    {
        if (($settings['allow_direct_main_changes'] ?? '0') === '1') {
            return null;
        }

        $mainBranch = trim((string) ($settings['default_branch'] ?? 'main'));
        if ($mainBranch === '') {
            return null;
        }

        $activeBranch = $this->get_active_branch_name($settings);
        if ($activeBranch === '' || $activeBranch !== $mainBranch) {
            return null;
        }

        return $this->failure(
            sprintf(
                __('Direct %s on the main branch is blocked. Switch to a working branch, make your changes there, and then merge that branch into %s. Enable the checkbox in settings if you want to allow direct main branch changes.', 'wordpress-git-connector'),
                $action,
                $mainBranch
            )
        );
    }

    private function set_remote(array $settings, string $remoteUrl): array
    {
        if ($remoteUrl === '') {
            return $this->failure(__('Remote URL is required.', 'wordpress-git-connector'));
        }

        if (!$this->is_ssh_remote($remoteUrl)) {
            return $this->failure(__('Use an SSH remote URL such as git@github.com:owner/repo.git. HTTPS remotes will not use the SSH key path.', 'wordpress-git-connector'));
        }

        $checkRemote = $this->run_git('remote get-url origin', $settings);
        if (!empty($checkRemote['success'])) {
            $result = $this->run_git('remote set-url origin ' . escapeshellarg($remoteUrl), $settings, null, __('Remote URL updated.', 'wordpress-git-connector'));
        } else {
            $result = $this->run_git('remote add origin ' . escapeshellarg($remoteUrl), $settings, null, __('Remote URL added.', 'wordpress-git-connector'));
        }

        if (!empty($result['success'])) {
            $settings['remote_url'] = $remoteUrl;
            update_option(self::OPTION_KEY, $settings);
        }

        return $result;
    }

    private function get_repo_info(array $settings): array
    {
        $info = [
            'active_branch' => '',
            'branches' => [],
            'remote_url' => $settings['remote_url'],
            'upstream_branch' => '',
            'ahead_by' => 0,
            'behind_by' => 0,
            'status_counts' => [
                'staged' => 0,
                'unstaged' => 0,
                'untracked' => 0,
            ],
            'latest_commit' => '',
        ];

        $path = $settings['local_path'];
        if ($path === '' || !is_dir($path . DIRECTORY_SEPARATOR . '.git')) {
            return $info;
        }

        $branchResult = $this->run_git('branch --list', $settings);
        if (!empty($branchResult['success'])) {
            $branches = preg_split('/\r\n|\r|\n/', trim((string) $branchResult['output']));
            foreach ($branches as $branch) {
                if ($branch === '') {
                    continue;
                }
                $branch = trim($branch);
                if (strpos($branch, '* ') === 0) {
                    $branchName = trim(substr($branch, 2));
                    $info['active_branch'] = $branchName;
                    $info['branches'][] = $branchName;
                } else {
                    $info['branches'][] = trim($branch);
                }
            }
        }

        $remoteResult = $this->run_git('remote get-url origin', $settings);
        if (!empty($remoteResult['success'])) {
            $info['remote_url'] = trim((string) $remoteResult['output']);
        }

        $upstreamResult = $this->run_git('rev-parse --abbrev-ref --symbolic-full-name @{u}', $settings);
        if (!empty($upstreamResult['success'])) {
            $info['upstream_branch'] = trim((string) $upstreamResult['output']);
        }

        if ($info['upstream_branch'] !== '') {
            $aheadBehindResult = $this->run_git('rev-list --left-right --count HEAD...@{u}', $settings);
            if (!empty($aheadBehindResult['success'])) {
                $parts = preg_split('/\s+/', trim((string) $aheadBehindResult['output']));
                if (count($parts) >= 2) {
                    $info['ahead_by'] = (int) $parts[0];
                    $info['behind_by'] = (int) $parts[1];
                }
            }
        }

        $statusResult = $this->run_git('status --short', $settings);
        if (!empty($statusResult['success'])) {
            $info['status_counts'] = $this->get_status_counts((string) $statusResult['output']);
        }

        $latestCommitResult = $this->run_git('log -1 --pretty=format:%h %s', $settings);
        if (!empty($latestCommitResult['success'])) {
            $info['latest_commit'] = trim((string) $latestCommitResult['output']);
        }

        return $info;
    }

    private function guard_remote_configuration(array $settings): ?array
    {
        $remoteUrl = trim((string) $settings['remote_url']);
        if ($remoteUrl === '' && $settings['local_path'] !== '' && is_dir($settings['local_path'] . DIRECTORY_SEPARATOR . '.git')) {
            $remoteResult = $this->run_git('remote get-url origin', $settings);
            if (!empty($remoteResult['success'])) {
                $remoteUrl = trim((string) $remoteResult['output']);
            }
        }

        if ($remoteUrl === '') {
            return $this->failure(__('No remote URL is configured.', 'wordpress-git-connector'));
        }

        if (!$this->is_ssh_remote($remoteUrl)) {
            return $this->failure(__('The configured remote is HTTPS. Change it to SSH, for example git@github.com:owner/repo.git, because HTTPS will ignore the SSH key path.', 'wordpress-git-connector'), $remoteUrl);
        }

        if (($settings['ssh_key_path'] ?? '') === '') {
            return $this->failure(__('SSH key path is required for remote Git actions.', 'wordpress-git-connector'));
        }

        if (!file_exists($settings['ssh_key_path'])) {
            return $this->failure(__('The configured SSH key path does not exist on the server.', 'wordpress-git-connector'), $settings['ssh_key_path']);
        }

        return null;
    }

    private function is_ssh_remote(string $remoteUrl): bool
    {
        return (bool) preg_match('/^(git@|ssh:\/\/)/i', $remoteUrl);
    }

    private function push_with_upstream(array $settings): array
    {
        $branch = $this->get_active_branch_name($settings);
        if ($branch === '') {
            return $this->failure(__('Could not detect the active branch for push.', 'wordpress-git-connector'));
        }

        if (!$this->repo_has_commits($settings)) {
            return $this->failure(__('This repository has no commits yet. Stage your files and create the first commit before pushing.', 'wordpress-git-connector'));
        }

        $upstreamCheck = $this->run_git('rev-parse --abbrev-ref --symbolic-full-name @{u}', $settings);
        if (!empty($upstreamCheck['success'])) {
            $pushResult = $this->run_git('push', $settings, null, __('Push completed successfully.', 'wordpress-git-connector'));
            return $this->decorate_push_result($pushResult, trim((string) $upstreamCheck['output']));
        }

        $pushResult = $this->run_git(
            'push --set-upstream origin ' . escapeshellarg($branch),
            $settings,
            null,
            __('Push completed and upstream branch was configured.', 'wordpress-git-connector')
        );
        return $this->decorate_push_result($pushResult, 'origin/' . $branch);
    }

    private function decorate_push_result(array $result, string $targetRef): array
    {
        if (empty($result['success'])) {
            return $result;
        }

        $output = trim((string) ($result['output'] ?? ''));
        $isUpToDate = $output === '' || stripos($output, 'Everything up-to-date') !== false;
        $result['message'] = $isUpToDate
            ? __('Remote is already up to date.', 'wordpress-git-connector')
            : __('Push completed successfully.', 'wordpress-git-connector');

        $details = [
            'Target: ' . $targetRef,
            $isUpToDate ? 'Status: Everything up to date.' : 'Status: Changes pushed.',
        ];
        if ($output !== '') {
            $details[] = $output;
        }
        $result['output'] = implode(PHP_EOL, $details);

        return $result;
    }

    private function guard_uncommitted_changes_before_push(array $settings): ?array
    {
        $statusResult = $this->run_git('status --short', $settings);
        if (empty($statusResult['success'])) {
            return $statusResult;
        }

        $output = trim((string) $statusResult['output']);
        if ($output === '') {
            return null;
        }

        return $this->failure(
            __('Push blocked because there are uncommitted changes. Stage your files if needed, create a commit, and then push again.', 'wordpress-git-connector'),
            $output
        );
    }

    private function get_active_branch_name(array $settings): string
    {
        $result = $this->run_git('branch --show-current', $settings);
        if (empty($result['success'])) {
            return '';
        }

        return trim((string) $result['output']);
    }

    private function repo_has_commits(array $settings): bool
    {
        $result = $this->run_git('rev-parse --verify HEAD', $settings);
        return !empty($result['success']);
    }

    private function ensure_gitignore(string $path): array
    {
        $gitignorePath = $path . DIRECTORY_SEPARATOR . '.gitignore';
        if (file_exists($gitignorePath)) {
            return [
                'success' => true,
                'message' => __('Existing .gitignore kept.', 'wordpress-git-connector'),
                'output' => $gitignorePath,
            ];
        }

        $contents = implode(PHP_EOL, [
            '# WordPress runtime files',
            'wp-config.php',
            'wp-content/cache/',
            'wp-content/uploads/',
            'wp-content/upgrade/',
            'wp-content/backups/',
            '',
            '# Logs and environment files',
            '*.log',
            '.env',
            '.env.*',
            '',
            '# OS/editor files',
            '.DS_Store',
            'Thumbs.db',
            '.idea/',
            '.vscode/',
            '',
        ]);

        $written = file_put_contents($gitignorePath, $contents);
        if ($written === false) {
            return $this->failure(__('Could not create the .gitignore file.', 'wordpress-git-connector'), $gitignorePath);
        }

        return [
            'success' => true,
            'message' => __('Created starter .gitignore.', 'wordpress-git-connector'),
            'output' => $gitignorePath,
        ];
    }

    private function run_git(string $arguments, array $settings, ?string $workingDir = null, ?string $successMessage = null): array
    {
        $gitBinary = $settings['git_binary'] ?: 'git';
        $workingDir = $workingDir ?: $settings['local_path'];

        if ($workingDir === '' || !is_dir($workingDir)) {
            return $this->failure(__('Configured working directory does not exist.', 'wordpress-git-connector'));
        }

        $env = is_array($_ENV) ? $_ENV : [];
        $sshCommand = $this->build_ssh_command($settings['ssh_key_path'] ?? '');
        if ($sshCommand !== '') {
            $env['GIT_SSH_COMMAND'] = $sshCommand;
        }

        $command = escapeshellarg($gitBinary) . ' ' . $arguments;
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $workingDir, $env);
        if (!is_resource($process)) {
            return $this->failure(__('Could not start the Git process.', 'wordpress-git-connector'));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $output = trim($stdout . PHP_EOL . $stderr);

        if ($exitCode !== 0) {
            return $this->failure(
                sprintf(
                    __('Git command failed with exit code %d.', 'wordpress-git-connector'),
                    $exitCode
                ),
                $output
            );
        }

        return [
            'success' => true,
            'message' => $successMessage ?: __('Git command completed successfully.', 'wordpress-git-connector'),
            'output' => $output,
        ];
    }

    private function build_ssh_command(string $sshKeyPath): string
    {
        if ($sshKeyPath === '') {
            return '';
        }

        $escapedPath = '"' . str_replace('"', '\"', $sshKeyPath) . '"';
        return 'ssh -i ' . $escapedPath . ' -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new';
    }

    private function failure(string $message, string $output = ''): array
    {
        return [
            'success' => false,
            'message' => $message,
            'output' => $output,
        ];
    }

    private function set_notice(string $type, string $message, string $output = ''): void
    {
        set_transient(self::NOTICE_TRANSIENT, [
            'type' => $type,
            'message' => $message,
            'output' => $output,
        ], 60);
    }

    private function get_notice(): ?array
    {
        $notice = get_transient(self::NOTICE_TRANSIENT);
        if ($notice) {
            delete_transient(self::NOTICE_TRANSIENT);
        }

        return is_array($notice) ? $notice : null;
    }

    private function record_activity(string $action, array $result, array $settings, float $durationSeconds = 0.0): void
    {
        $log = get_option(self::LOG_OPTION_KEY, []);
        if (!is_array($log)) {
            $log = $log !== '' ? [[
                'title' => __('Previous Output', 'wordpress-git-connector'),
                'message' => __('Stored output from an older plugin version.', 'wordpress-git-connector'),
                'output' => (string) $log,
                'success' => true,
                'time' => current_time('mysql'),
                'meta' => '',
                'action' => 'legacy',
                'duration_ms' => 0,
            ]] : [];
        }

        array_unshift($log, [
            'title' => $this->get_action_label($action),
            'message' => (string) ($result['message'] ?? __('Git action completed.', 'wordpress-git-connector')),
            'output' => trim((string) ($result['output'] ?? '')),
            'success' => !empty($result['success']),
            'time' => current_time('mysql'),
            'meta' => $this->build_activity_meta($settings),
            'action' => $action,
            'duration_ms' => (int) round($durationSeconds * 1000),
        ]);

        update_option(self::LOG_OPTION_KEY, array_slice($log, 0, 12), false);
    }

    private function get_activity_log(): array
    {
        $log = get_option(self::LOG_OPTION_KEY, []);
        return is_array($log) ? $log : [];
    }

    private function get_filtered_activity_log(string $statusFilter = 'all', string $actionFilter = 'all'): array
    {
        return array_values(array_filter($this->get_activity_log(), static function (array $entry) use ($statusFilter, $actionFilter): bool {
            if ($statusFilter === 'success' && empty($entry['success'])) {
                return false;
            }
            if ($statusFilter === 'error' && !empty($entry['success'])) {
                return false;
            }
            if ($actionFilter !== 'all' && ($entry['action'] ?? '') !== $actionFilter) {
                return false;
            }
            return true;
        }));
    }

    private function get_activity_actions(): array
    {
        $actions = [];
        foreach ($this->get_activity_log() as $entry) {
            $action = $entry['action'] ?? '';
            if ($action === '') {
                continue;
            }
            $actions[$action] = $this->get_action_label($action);
        }

        return $actions;
    }

    private function get_action_label(string $action): string
    {
        $labels = [
            'save_settings' => __('Saved Settings', 'wordpress-git-connector'),
            'initialize_repo' => __('Initialized Repository', 'wordpress-git-connector'),
            'test_connection' => __('Tested Connection', 'wordpress-git-connector'),
            'test_remote' => __('Tested Remote SSH', 'wordpress-git-connector'),
            'clone_repo' => __('Cloned Repository', 'wordpress-git-connector'),
            'connect_repo' => __('Connected Repository', 'wordpress-git-connector'),
            'fetch' => __('Fetched Remote Updates', 'wordpress-git-connector'),
            'sync_remote_branches' => __('Imported Remote Branches', 'wordpress-git-connector'),
            'pull' => __('Pulled Remote Changes', 'wordpress-git-connector'),
            'push' => __('Pushed Commits', 'wordpress-git-connector'),
            'status' => __('Refreshed Status', 'wordpress-git-connector'),
            'add_all' => __('Staged All Changes', 'wordpress-git-connector'),
            'commit' => __('Created Commit', 'wordpress-git-connector'),
            'set_remote' => __('Updated Remote', 'wordpress-git-connector'),
            'create_branch' => __('Created Branch', 'wordpress-git-connector'),
            'merge_into_active' => __('Merged Branch', 'wordpress-git-connector'),
            'checkout_branch' => __('Switched Branch', 'wordpress-git-connector'),
            'delete_branch' => __('Deleted Branch', 'wordpress-git-connector'),
        ];

        return $labels[$action] ?? __('Git Action', 'wordpress-git-connector');
    }

    private function build_activity_meta(array $settings): string
    {
        $parts = [];
        $branch = $this->get_active_branch_name($settings);
        if ($branch !== '') {
            $parts[] = 'Branch: ' . $branch;
        }
        if (!empty($settings['local_path'])) {
            $parts[] = 'Repo: ' . $settings['local_path'];
        }

        return implode(' | ', $parts);
    }

    private function format_status_summary(string $statusOutput): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $statusOutput);
        $summary = [];
        $summary[] = 'Staged and pending file summary:';

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }

            $indexStatus = substr($line, 0, 1);
            $worktreeStatus = substr($line, 1, 1);
            $file = trim(substr($line, 3));
            $tags = [];

            if ($indexStatus !== ' ' && $indexStatus !== '?') {
                $tags[] = 'staged';
            }
            if ($worktreeStatus !== ' ' && $worktreeStatus !== '?') {
                $tags[] = 'unstaged';
            }
            if ($indexStatus === '?' || $worktreeStatus === '?') {
                $tags[] = 'untracked';
            }

            $summary[] = sprintf(
                '[%s] %s',
                $tags ? implode(', ', $tags) : 'tracked',
                $file
            );
        }

        return implode(PHP_EOL, $summary);
    }

    private function get_status_counts(string $statusOutput): array
    {
        $counts = [
            'staged' => 0,
            'unstaged' => 0,
            'untracked' => 0,
        ];

        $lines = preg_split('/\r\n|\r|\n/', $statusOutput);
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }

            $indexStatus = substr($line, 0, 1);
            $worktreeStatus = substr($line, 1, 1);

            if ($indexStatus !== ' ' && $indexStatus !== '?') {
                $counts['staged']++;
            }
            if ($worktreeStatus !== ' ' && $worktreeStatus !== '?') {
                $counts['unstaged']++;
            }
            if ($indexStatus === '?' || $worktreeStatus === '?') {
                $counts['untracked']++;
            }
        }

        return $counts;
    }

    private function redirect_back(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    public function handle_backup_download(): void
    {
        $this->ensure_access();

        $file = isset($_GET['file']) ? wp_unslash($_GET['file']) : '';
        check_admin_referer('wgc_download_backup_' . $file);

        $settings = $this->get_settings();
        $validated = $this->validate_backup_file_path($settings, $file);
        if ($validated === '') {
            wp_die(esc_html__('Invalid backup file.', 'wordpress-git-connector'));
        }

        if (!is_file($validated) || !is_readable($validated)) {
            wp_die(esc_html__('Backup file is not available.', 'wordpress-git-connector'));
        }

        nocache_headers();
        header('Content-Type: text/x-diff');
        header('Content-Disposition: attachment; filename="' . basename($validated) . '"');
        header('Content-Length: ' . (string) filesize($validated));
        readfile($validated);
        exit;
    }

    private function get_backup_files(array $settings): array
    {
        $backupDir = $this->get_backup_directory($settings);
        if ($backupDir === '' || !is_dir($backupDir)) {
            return [];
        }

        $files = glob($backupDir . DIRECTORY_SEPARATOR . '*.patch') ?: [];
        rsort($files);

        $result = [];
        foreach (array_slice($files, 0, 20) as $file) {
            $result[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => is_file($file) ? filesize($file) : 0,
            ];
        }

        return $result;
    }

    private function get_backup_download_url(string $filePath): string
    {
        $url = add_query_arg([
            'action' => 'wgc_download_backup',
            'file' => $filePath,
        ], admin_url('admin-post.php'));

        return wp_nonce_url($url, 'wgc_download_backup_' . $filePath);
    }

    private function get_backup_directory(array $settings): string
    {
        $repoPath = rtrim((string) ($settings['local_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($repoPath === '') {
            return '';
        }

        return $repoPath . DIRECTORY_SEPARATOR . '.git-branch-backups';
    }

    private function validate_backup_file_path(array $settings, string $filePath): string
    {
        $backupDir = $this->get_backup_directory($settings);
        if ($backupDir === '') {
            return '';
        }

        $realBackupDir = realpath($backupDir);
        $realFilePath = realpath($filePath);
        if ($realBackupDir === false || $realFilePath === false) {
            return '';
        }

        if (strpos($realFilePath, $realBackupDir . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }

        return $realFilePath;
    }
}
