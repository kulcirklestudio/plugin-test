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
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_wgc_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_wgc_git_action', [$this, 'handle_git_action']);
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
            'git_binary' => isset($input['git_binary']) ? sanitize_text_field($input['git_binary']) : $defaults['git_binary'],
            'repo_mode' => isset($input['repo_mode']) && in_array($input['repo_mode'], ['existing', 'clone'], true) ? $input['repo_mode'] : $defaults['repo_mode'],
            'local_path' => isset($input['local_path']) ? $this->sanitize_path($input['local_path']) : '',
            'clone_parent' => isset($input['clone_parent']) ? $this->sanitize_path($input['clone_parent']) : '',
            'remote_url' => isset($input['remote_url']) ? sanitize_text_field($input['remote_url']) : '',
            'ssh_key_path' => isset($input['ssh_key_path']) ? $this->sanitize_path($input['ssh_key_path']) : '',
            'default_branch' => isset($input['default_branch']) ? sanitize_text_field($input['default_branch']) : 'main',
            'author_name' => isset($input['author_name']) ? sanitize_text_field($input['author_name']) : '',
            'author_email' => isset($input['author_email']) ? sanitize_email($input['author_email']) : '',
        ];
    }

    public function handle_save_settings(): void
    {
        $this->ensure_access();
        check_admin_referer('wgc_save_settings');

        $settings = $this->sanitize_settings($_POST[self::OPTION_KEY] ?? []);
        update_option(self::OPTION_KEY, array_merge($this->default_settings(), $settings));

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

        switch ($action) {
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
            case 'pull':
                $result = $this->guard_remote_configuration($settings);
                if ($result === null) {
                    $result = $this->run_git('pull --rebase', $settings);
                }
                break;
            case 'push':
                $result = $this->guard_remote_configuration($settings);
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
                $result = $this->commit_changes($settings, $message);
                break;
            case 'set_remote':
                $remoteUrl = isset($_POST['remote_url']) ? sanitize_text_field(wp_unslash($_POST['remote_url'])) : '';
                $result = $this->set_remote($settings, $remoteUrl);
                break;
            case 'create_branch':
                $branchName = isset($_POST['branch_name']) ? sanitize_text_field(wp_unslash($_POST['branch_name'])) : '';
                $result = $this->run_git('checkout -b ' . escapeshellarg($branchName), $settings);
                break;
            case 'checkout_branch':
                $branchName = isset($_POST['active_branch']) ? sanitize_text_field(wp_unslash($_POST['active_branch'])) : '';
                $result = $this->run_git('checkout ' . escapeshellarg($branchName), $settings);
                break;
            case 'delete_branch':
                $branchName = isset($_POST['branch_name']) ? sanitize_text_field(wp_unslash($_POST['branch_name'])) : '';
                $result = $this->run_git('branch -D ' . escapeshellarg($branchName), $settings);
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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WordPress Git Connector', 'wordpress-git-connector'); ?></h1>
            <p><?php esc_html_e('Connect a local repository or clone from SSH, then manage commits, push, pull, remotes, and branches from this screen.', 'wordpress-git-connector'); ?></p>

            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><strong><?php echo esc_html($notice['message']); ?></strong></p>
                    <?php if (!empty($notice['output'])) : ?>
                        <pre style="white-space: pre-wrap; max-height: 320px; overflow: auto;"><?php echo esc_html($notice['output']); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">
                <div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wgc_save_settings'); ?>
                        <input type="hidden" name="action" value="wgc_save_settings">

                        <table class="form-table" role="presentation">
                            <tbody>
                            <tr>
                                <th scope="row"><label for="wgc_git_binary"><?php esc_html_e('Git Binary', 'wordpress-git-connector'); ?></label></th>
                                <td>
                                    <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[git_binary]" id="wgc_git_binary" type="text" class="regular-text" value="<?php echo esc_attr($settings['git_binary']); ?>">
                                    <p class="description"><?php esc_html_e('Use git if it is in PATH, or provide the full binary path.', 'wordpress-git-connector'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Connection Mode', 'wordpress-git-connector'); ?></th>
                                <td>
                                    <label><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[repo_mode]" value="existing" <?php checked($settings['repo_mode'], 'existing'); ?>> <?php esc_html_e('Use existing local repo', 'wordpress-git-connector'); ?></label><br>
                                    <label><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[repo_mode]" value="clone" <?php checked($settings['repo_mode'], 'clone'); ?>> <?php esc_html_e('Clone SSH repo to local path', 'wordpress-git-connector'); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wgc_local_path"><?php esc_html_e('Local Repo Path', 'wordpress-git-connector'); ?></label></th>
                                <td>
                                    <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[local_path]" id="wgc_local_path" type="text" class="regular-text code" value="<?php echo esc_attr($settings['local_path']); ?>">
                                    <p class="description"><?php esc_html_e('Absolute path to an existing repository or the final clone directory.', 'wordpress-git-connector'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wgc_clone_parent"><?php esc_html_e('Clone Parent Path', 'wordpress-git-connector'); ?></label></th>
                                <td>
                                    <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[clone_parent]" id="wgc_clone_parent" type="text" class="regular-text code" value="<?php echo esc_attr($settings['clone_parent']); ?>">
                                    <p class="description"><?php esc_html_e('Parent folder used when cloning. Leave empty to use the parent of Local Repo Path.', 'wordpress-git-connector'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wgc_remote_url"><?php esc_html_e('SSH Remote URL', 'wordpress-git-connector'); ?></label></th>
                                <td>
                                    <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[remote_url]" id="wgc_remote_url" type="text" class="regular-text code" value="<?php echo esc_attr($settings['remote_url']); ?>" placeholder="git@github.com:owner/repo.git">
                                    <p class="description"><?php esc_html_e('SSH remote used for clone, push, pull, and remote updates.', 'wordpress-git-connector'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wgc_ssh_key_path"><?php esc_html_e('SSH Key Path', 'wordpress-git-connector'); ?></label></th>
                                <td>
                                    <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[ssh_key_path]" id="wgc_ssh_key_path" type="text" class="regular-text code" value="<?php echo esc_attr($settings['ssh_key_path']); ?>">
                                    <p class="description"><?php esc_html_e('Absolute path to the private SSH key file. Username/password is not required.', 'wordpress-git-connector'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wgc_default_branch"><?php esc_html_e('Default Branch', 'wordpress-git-connector'); ?></label></th>
                                <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_branch]" id="wgc_default_branch" type="text" class="regular-text" value="<?php echo esc_attr($settings['default_branch']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wgc_author_name"><?php esc_html_e('Commit Author Name', 'wordpress-git-connector'); ?></label></th>
                                <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[author_name]" id="wgc_author_name" type="text" class="regular-text" value="<?php echo esc_attr($settings['author_name']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wgc_author_email"><?php esc_html_e('Commit Author Email', 'wordpress-git-connector'); ?></label></th>
                                <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[author_email]" id="wgc_author_email" type="email" class="regular-text" value="<?php echo esc_attr($settings['author_email']); ?>"></td>
                            </tr>
                            </tbody>
                        </table>

                        <?php submit_button(__('Save Connection Settings', 'wordpress-git-connector')); ?>
                    </form>

                    <hr>

                    <h2><?php esc_html_e('Repository Actions', 'wordpress-git-connector'); ?></h2>
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(280px,1fr));gap:20px;">
                        <?php $this->render_action_card(__('Connection', 'wordpress-git-connector'), function () use ($settings) { ?>
                            <?php $this->render_action_form('initialize_repo', __('Initialize Local Repo', 'wordpress-git-connector')); ?>
                            <?php $this->render_action_form('connect_repo', __('Connect Existing Repo', 'wordpress-git-connector')); ?>
                            <?php $this->render_action_form('clone_repo', __('Clone SSH Repo', 'wordpress-git-connector')); ?>
                            <?php $this->render_action_form('test_connection', __('Test Connection', 'wordpress-git-connector')); ?>
                            <?php $this->render_action_form('test_remote', __('Test Remote SSH', 'wordpress-git-connector')); ?>
                            <?php $this->render_remote_update_form($settings['remote_url']); ?>
                        <?php }); ?>

                        <?php $this->render_action_card(__('Sync', 'wordpress-git-connector'), function () { ?>
                            <?php $this->render_action_form('fetch', __('Fetch', 'wordpress-git-connector')); ?>
                            <?php $this->render_action_form('pull', __('Pull', 'wordpress-git-connector')); ?>
                            <?php $this->render_action_form('push', __('Push', 'wordpress-git-connector')); ?>
                            <?php $this->render_action_form('status', __('Refresh Status', 'wordpress-git-connector')); ?>
                        <?php }); ?>

                        <?php $this->render_action_card(__('Commit', 'wordpress-git-connector'), function () { ?>
                            <?php $this->render_action_form('add_all', __('Stage All Changes', 'wordpress-git-connector')); ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="commit">
                                <p>
                                    <label for="wgc_commit_message"><strong><?php esc_html_e('Commit Message', 'wordpress-git-connector'); ?></strong></label><br>
                                    <textarea id="wgc_commit_message" name="commit_message" rows="4" class="large-text" required></textarea>
                                </p>
                                <?php submit_button(__('Commit Changes', 'wordpress-git-connector'), 'secondary', '', false); ?>
                            </form>
                        <?php }); ?>

                        <?php $this->render_action_card(__('Branches', 'wordpress-git-connector'), function () use ($repoInfo) { ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="checkout_branch">
                                <p>
                                    <label for="wgc_active_branch"><strong><?php esc_html_e('Active Branch', 'wordpress-git-connector'); ?></strong></label><br>
                                    <select id="wgc_active_branch" name="active_branch">
                                        <?php foreach ($repoInfo['branches'] as $branch) : ?>
                                            <option value="<?php echo esc_attr($branch); ?>" <?php selected($repoInfo['active_branch'], $branch); ?>><?php echo esc_html($branch); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </p>
                                <?php submit_button(__('Switch Branch', 'wordpress-git-connector'), 'secondary', '', false); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="create_branch">
                                <p>
                                    <label for="wgc_branch_name"><strong><?php esc_html_e('New Branch Name', 'wordpress-git-connector'); ?></strong></label><br>
                                    <input id="wgc_branch_name" name="branch_name" type="text" class="regular-text" required>
                                </p>
                                <?php submit_button(__('Create Branch', 'wordpress-git-connector'), 'secondary', '', false); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="delete_branch">
                                <p>
                                    <label for="wgc_delete_branch"><strong><?php esc_html_e('Delete Branch', 'wordpress-git-connector'); ?></strong></label><br>
                                    <input id="wgc_delete_branch" name="branch_name" type="text" class="regular-text" required>
                                </p>
                                <?php submit_button(__('Delete Branch', 'wordpress-git-connector'), 'delete', '', false); ?>
                            </form>
                        <?php }); ?>
                    </div>
                </div>

                <div>
                    <h2><?php esc_html_e('Repository Summary', 'wordpress-git-connector'); ?></h2>
                    <table class="widefat striped">
                        <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Local Path', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($settings['local_path'] ?: __('Not configured', 'wordpress-git-connector')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Remote URL', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($repoInfo['remote_url'] ?: __('Not available', 'wordpress-git-connector')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Active Branch', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($repoInfo['active_branch'] ?: __('Not available', 'wordpress-git-connector')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Branches', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($repoInfo['branches'] ? implode(', ', $repoInfo['branches']) : __('None detected', 'wordpress-git-connector')); ?></td>
                        </tr>
                        </tbody>
                    </table>

                    <h2 style="margin-top:20px;"><?php esc_html_e('Latest Output', 'wordpress-git-connector'); ?></h2>
                    <pre style="white-space: pre-wrap; background:#fff; border:1px solid #ccd0d4; padding:12px; min-height:220px; overflow:auto;"><?php echo esc_html((string) get_option(self::LOG_OPTION_KEY, __('No Git output yet.', 'wordpress-git-connector'))); ?></pre>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_action_card(string $title, callable $callback): void
    {
        ?>
        <div style="background:#fff;border:1px solid #ccd0d4;padding:16px;">
            <h3 style="margin-top:0;"><?php echo esc_html($title); ?></h3>
            <?php $callback(); ?>
        </div>
        <?php
    }

    private function render_action_form(string $action, string $label): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
            <?php wp_nonce_field('wgc_git_action'); ?>
            <input type="hidden" name="action" value="wgc_git_action">
            <input type="hidden" name="wgc_action" value="<?php echo esc_attr($action); ?>">
            <?php submit_button($label, 'secondary', '', false); ?>
        </form>
        <?php
    }

    private function render_remote_update_form(string $remoteUrl): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
        ];
    }

    private function get_settings(): array
    {
        return array_merge($this->default_settings(), get_option(self::OPTION_KEY, []));
    }

    private function ensure_access(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage this plugin.', 'wordpress-git-connector'));
        }
    }

    private function sanitize_path(string $path): string
    {
        return trim(wp_unslash($path));
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

        return $this->run_git('commit -m ' . escapeshellarg($message), $settings, null, __('Commit created successfully.', 'wordpress-git-connector'));
    }

    private function stage_all_changes(array $settings): array
    {
        return $this->run_git(
            '-c core.autocrlf=false -c core.safecrlf=false add -A',
            $settings,
            null,
            __('All changes staged successfully.', 'wordpress-git-connector')
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
            return $this->run_git('push', $settings, null, __('Push completed successfully.', 'wordpress-git-connector'));
        }

        return $this->run_git(
            'push --set-upstream origin ' . escapeshellarg($branch),
            $settings,
            null,
            __('Push completed and upstream branch was configured.', 'wordpress-git-connector')
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
        update_option(self::LOG_OPTION_KEY, $output);

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
        update_option(self::LOG_OPTION_KEY, $output);

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

    private function redirect_back(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }
}
