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
            '1.1.0'
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
            'git_binary' => isset($input['git_binary']) ? sanitize_text_field($input['git_binary']) : $defaults['git_binary'],
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
        ], $settings);

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
        <div class="wrap wgc-admin">
            <div class="wgc-hero">
                <div>
                    <h1><?php esc_html_e('WordPress Git Connector', 'wordpress-git-connector'); ?></h1>
                    <p><?php esc_html_e('Manage local repositories, branch workflows, merges, commits, and SSH remotes from a cleaner WordPress admin interface.', 'wordpress-git-connector'); ?>
                    </p>
                </div>
                <div class="wgc-hero-meta">
                    <span class="wgc-pill"><?php esc_html_e('Current Branch', 'wordpress-git-connector'); ?>:
                        <?php echo esc_html($repoInfo['active_branch'] ?: __('Unknown', 'wordpress-git-connector')); ?></span>
                    <span class="wgc-pill wgc-pill-accent"><?php esc_html_e('Main Branch', 'wordpress-git-connector'); ?>:
                        <?php echo esc_html($settings['default_branch'] ?: __('Not set', 'wordpress-git-connector')); ?></span>
                </div>
            </div>

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible wgc-notice">
                    <p><strong><?php echo esc_html($notice['message']); ?></strong></p>
                    <?php if (!empty($notice['output'])): ?>
                        <pre class="wgc-output"><?php echo esc_html($notice['output']); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="wgc-layout">
                <div class="wgc-main">
                    <div class="wgc-panel wgc-panel-settings">
                        <div class="wgc-panel-head">
                            <h2><?php esc_html_e('Connection Settings', 'wordpress-git-connector'); ?></h2>
                            <p><?php esc_html_e('Define the local repository path, SSH remote, Git binary, and branch protection rules.', 'wordpress-git-connector'); ?>
                            </p>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                            class="wgc-settings-form">
                            <?php wp_nonce_field('wgc_save_settings'); ?>
                            <input type="hidden" name="action" value="wgc_save_settings">

                            <table class="form-table" role="presentation">
                                <tbody>
                                    <tr>
                                        <th scope="row"><label
                                                for="wgc_git_binary"><?php esc_html_e('Git Binary', 'wordpress-git-connector'); ?></label>
                                        </th>
                                        <td>
                                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[git_binary]"
                                                id="wgc_git_binary" type="text" class="regular-text"
                                                value="<?php echo esc_attr($settings['git_binary']); ?>">
                                            <p class="description">
                                                <?php esc_html_e('Use git if it is in PATH, or provide the full binary path.', 'wordpress-git-connector'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e('Connection Mode', 'wordpress-git-connector'); ?></th>
                                        <td>
                                            <label><input type="radio"
                                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[repo_mode]" value="existing"
                                                    <?php checked($settings['repo_mode'], 'existing'); ?>>
                                                <?php esc_html_e('Use existing local repo', 'wordpress-git-connector'); ?></label><br>
                                            <label><input type="radio"
                                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[repo_mode]" value="clone"
                                                    <?php checked($settings['repo_mode'], 'clone'); ?>>
                                                <?php esc_html_e('Clone SSH repo to local path', 'wordpress-git-connector'); ?></label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label
                                                for="wgc_local_path"><?php esc_html_e('Local Repo Path', 'wordpress-git-connector'); ?></label>
                                        </th>
                                        <td>
                                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[local_path]"
                                                id="wgc_local_path" type="text" class="regular-text code"
                                                value="<?php echo esc_attr($settings['local_path']); ?>">
                                            <p class="description">
                                                <?php esc_html_e('Absolute path to an existing repository or the final clone directory.', 'wordpress-git-connector'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label
                                                for="wgc_clone_parent"><?php esc_html_e('Clone Parent Path', 'wordpress-git-connector'); ?></label>
                                        </th>
                                        <td>
                                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[clone_parent]"
                                                id="wgc_clone_parent" type="text" class="regular-text code"
                                                value="<?php echo esc_attr($settings['clone_parent']); ?>">
                                            <p class="description">
                                                <?php esc_html_e('Parent folder used when cloning. Leave empty to use the parent of Local Repo Path.', 'wordpress-git-connector'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label
                                                for="wgc_remote_url"><?php esc_html_e('SSH Remote URL', 'wordpress-git-connector'); ?></label>
                                        </th>
                                        <td>
                                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[remote_url]"
                                                id="wgc_remote_url" type="text" class="regular-text code"
                                                value="<?php echo esc_attr($settings['remote_url']); ?>"
                                                placeholder="git@github.com:owner/repo.git">
                                            <p class="description">
                                                <?php esc_html_e('SSH remote used for clone, push, pull, and remote updates.', 'wordpress-git-connector'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label
                                                for="wgc_ssh_key_path"><?php esc_html_e('SSH Key Path', 'wordpress-git-connector'); ?></label>
                                        </th>
                                        <td>
                                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[ssh_key_path]"
                                                id="wgc_ssh_key_path" type="text" class="regular-text code"
                                                value="<?php echo esc_attr($settings['ssh_key_path']); ?>">
                                            <p class="description">
                                                <?php esc_html_e('Absolute path to the private SSH key file. Username/password is not required.', 'wordpress-git-connector'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label
                                                for="wgc_default_branch"><?php esc_html_e('Default Branch', 'wordpress-git-connector'); ?></label>
                                        </th>
                                        <td>
                                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_branch]"
                                                id="wgc_default_branch" type="text" class="regular-text"
                                                value="<?php echo esc_attr($settings['default_branch']); ?>">
                                            <p class="description">
                                                <?php esc_html_e('This is treated as the main protected branch. Users should normally work on another branch and merge into this branch.', 'wordpress-git-connector'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label
                                                for="wgc_author_name"><?php esc_html_e('Commit Author Name', 'wordpress-git-connector'); ?></label>
                                        </th>
                                        <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[author_name]"
                                                id="wgc_author_name" type="text" class="regular-text"
                                                value="<?php echo esc_attr($settings['author_name']); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label
                                                for="wgc_author_email"><?php esc_html_e('Commit Author Email', 'wordpress-git-connector'); ?></label>
                                        </th>
                                        <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[author_email]"
                                                id="wgc_author_email" type="email" class="regular-text"
                                                value="<?php echo esc_attr($settings['author_email']); ?>"></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <?php esc_html_e('Direct Main Branch Changes', 'wordpress-git-connector'); ?></th>
                                        <td>
                                            <label>
                                                <input
                                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_direct_main_changes]"
                                                    type="checkbox" value="1" <?php checked($settings['allow_direct_main_changes'], '1'); ?>>
                                                <?php esc_html_e('Allow direct commit and push actions when the active branch is the configured main branch', 'wordpress-git-connector'); ?>
                                            </label>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <?php submit_button(__('Save Connection Settings', 'wordpress-git-connector'), 'primary wgc-primary-button'); ?>
                        </form>
                    </div>

                    <div class="wgc-section-head">
                        <h2><?php esc_html_e('Repository Actions', 'wordpress-git-connector'); ?></h2>
                        <p><?php esc_html_e('Work from top to bottom: set up the repository, sync branches, create commits, then manage merges and cleanup.', 'wordpress-git-connector'); ?>
                        </p>
                    </div>
                    <div class="wgc-workflow">
                        <?php $this->render_action_card(
                            __('Repository Setup', 'wordpress-git-connector'),
                            __('Use these actions to initialize a new local repository, connect an existing one, or verify the SSH remote connection.', 'wordpress-git-connector'),
                            __('Step 1', 'wordpress-git-connector'),
                            function () use ($settings) { ?>
                            <?php $this->render_action_button_group([
                                                    ['action' => 'initialize_repo', 'label' => __('Initialize Local Repo', 'wordpress-git-connector')],
                                                    ['action' => 'connect_repo', 'label' => __('Connect Existing Repo', 'wordpress-git-connector')],
                                                    ['action' => 'clone_repo', 'label' => __('Clone SSH Repo', 'wordpress-git-connector')],
                                                    ['action' => 'test_connection', 'label' => __('Test Connection', 'wordpress-git-connector')],
                                                    ['action' => 'test_remote', 'label' => __('Test Remote SSH', 'wordpress-git-connector')],
                                                ]); ?>
                            <?php $this->render_remote_update_form($settings['remote_url']); ?>
                        <?php }
                        ); ?>

                        <?php $this->render_action_card(
                            __('Sync And Remote', 'wordpress-git-connector'),
                            __('Fetch updates, import remote branches, pull the active branch, or push your local commits to the remote repository.', 'wordpress-git-connector'),
                            __('Step 2', 'wordpress-git-connector'),
                            function () { ?>
                            <?php $this->render_action_button_group([
                                    ['action' => 'fetch', 'label' => __('Fetch', 'wordpress-git-connector')],
                                    ['action' => 'sync_remote_branches', 'label' => __('Import Remote Branches', 'wordpress-git-connector')],
                                    ['action' => 'pull', 'label' => __('Pull', 'wordpress-git-connector')],
                                    ['action' => 'push', 'label' => __('Push', 'wordpress-git-connector')],
                                    ['action' => 'status', 'label' => __('Refresh Status', 'wordpress-git-connector')],
                                ]); ?>
                        <?php }
                        ); ?>

                        <?php $this->render_action_card(
                            __('Commit Changes', 'wordpress-git-connector'),
                            __('Stage modified files first, then create a commit with a message describing the changes.', 'wordpress-git-connector'),
                            __('Step 3', 'wordpress-git-connector'),
                            function () { ?>
                            <?php $this->render_action_button_group([
                                    ['action' => 'add_all', 'label' => __('Stage All Changes', 'wordpress-git-connector')],
                                ]); ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-stack-form">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="commit">
                                <p>
                                    <label
                                        for="wgc_commit_message"><strong><?php esc_html_e('Commit Message', 'wordpress-git-connector'); ?></strong></label><br>
                                    <textarea id="wgc_commit_message" name="commit_message" rows="4" class="large-text"
                                        required></textarea>
                                </p>
                                <?php submit_button(__('Commit Changes', 'wordpress-git-connector'), 'secondary', '', false); ?>
                            </form>
                        <?php }
                        ); ?>

                        <?php $this->render_action_card(
                            __('Branch Management', 'wordpress-git-connector'),
                            __('Switch branches, create new ones, merge another branch into the active branch, or delete branches you no longer need.', 'wordpress-git-connector'),
                            __('Step 4', 'wordpress-git-connector'),
                            function () use ($repoInfo, $settings) { ?>
                            <p><strong><?php esc_html_e('Current Active Branch:', 'wordpress-git-connector'); ?></strong>
                                <?php echo esc_html($repoInfo['active_branch'] ?: __('Not available', 'wordpress-git-connector')); ?>
                            </p>
                            <p><strong><?php esc_html_e('Configured Main Branch:', 'wordpress-git-connector'); ?></strong>
                                <?php echo esc_html($settings['default_branch'] ?: __('Not set', 'wordpress-git-connector')); ?></p>
                            <?php if (!empty($repoInfo['active_branch']) && $repoInfo['active_branch'] === $settings['default_branch'] && $settings['allow_direct_main_changes'] !== '1'): ?>
                                <p style="padding:10px;border-left:4px solid #d63638;background:#fcf0f1;">
                                    <?php esc_html_e('Direct commit and push on the main branch are currently blocked. Create or switch to a working branch, then merge it into the main branch.', 'wordpress-git-connector'); ?>
                                </p>
                            <?php endif; ?>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="checkout_branch">
                                <p>
                                    <label
                                        for="wgc_active_branch"><strong><?php esc_html_e('Active Branch', 'wordpress-git-connector'); ?></strong></label><br>
                                    <select id="wgc_active_branch" name="active_branch">
                                        <?php $this->render_branch_options($repoInfo['branches'], $settings['default_branch'], $repoInfo['active_branch']); ?>
                                    </select>
                                </p>
                                <?php submit_button(__('Switch Branch', 'wordpress-git-connector'), 'secondary', '', false); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="create_branch">
                                <p>
                                    <label
                                        for="wgc_branch_name"><strong><?php esc_html_e('New Branch Name', 'wordpress-git-connector'); ?></strong></label><br>
                                    <input id="wgc_branch_name" name="branch_name" type="text" class="regular-text" required>
                                </p>
                                <?php submit_button(__('Create Branch', 'wordpress-git-connector'), 'secondary', '', false); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="merge_into_active">
                                <p>
                                    <label
                                        for="wgc_source_branch"><strong><?php esc_html_e('Merge Branch Into Active Branch', 'wordpress-git-connector'); ?></strong></label><br>
                                    <select id="wgc_source_branch" name="source_branch">
                                        <?php $this->render_branch_options($repoInfo['branches'], $settings['default_branch'], '', $repoInfo['active_branch']); ?>
                                    </select>
                                </p>
                                <p class="description">
                                    <?php esc_html_e('The selected branch will be merged into the currently active branch. Use this to bring working branch changes into the main branch. If conflicts happen, Git output will be shown below.', 'wordpress-git-connector'); ?>
                                </p>
                                <?php submit_button(__('Merge Into Active Branch', 'wordpress-git-connector'), 'secondary', '', false); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="delete_branch">
                                <p>
                                    <label
                                        for="wgc_delete_branch"><strong><?php esc_html_e('Delete Branch', 'wordpress-git-connector'); ?></strong></label><br>
                                    <select id="wgc_delete_branch" name="branch_name">
                                        <?php $this->render_branch_options($repoInfo['branches'], $settings['default_branch'], '', $repoInfo['active_branch']); ?>
                                    </select>
                                </p>
                                <p>
                                    <label>
                                        <input name="create_backup_branch" type="checkbox" value="1" checked>
                                        <?php esc_html_e('Create a backup branch before deleting', 'wordpress-git-connector'); ?>
                                    </label>
                                </p>
                                <p>
                                    <label>
                                        <input name="delete_remote_branch" type="checkbox" value="1">
                                        <?php esc_html_e('Also delete this branch from remote origin', 'wordpress-git-connector'); ?>
                                    </label>
                                </p>
                                <p>
                                    <label>
                                        <input name="create_backup_file" type="checkbox" value="1" checked>
                                        <?php esc_html_e('Create a downloadable local backup file before deleting', 'wordpress-git-connector'); ?>
                                    </label>
                                </p>
                                <p class="description">
                                    <?php esc_html_e('The active branch cannot be deleted. If backup is enabled, a branch named backup/<branch>-YYYYmmdd-HHMMSS will be created first.', 'wordpress-git-connector'); ?>
                                </p>
                                <?php submit_button(__('Delete Branch', 'wordpress-git-connector'), 'delete', '', false); ?>
                            </form>
                        <?php }
                        ); ?>
                    </div>
                </div>

                <aside class="wgc-sidebar">
                    <div class="wgc-panel">
                        <h2><?php esc_html_e('Repository Summary', 'wordpress-git-connector'); ?></h2>
                        <table class="widefat striped wgc-summary-table">
                            <tbody>
                                <tr>
                                    <td><strong><?php esc_html_e('Local Path', 'wordpress-git-connector'); ?></strong></td>
                                    <td><?php echo esc_html($settings['local_path'] ?: __('Not configured', 'wordpress-git-connector')); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Remote URL', 'wordpress-git-connector'); ?></strong></td>
                                    <td><?php echo esc_html($repoInfo['remote_url'] ?: __('Not available', 'wordpress-git-connector')); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Active Branch', 'wordpress-git-connector'); ?></strong></td>
                                    <td><?php echo esc_html($repoInfo['active_branch'] ?: __('Not available', 'wordpress-git-connector')); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Branches', 'wordpress-git-connector'); ?></strong></td>
                                    <td><?php echo esc_html($repoInfo['branches'] ? implode(', ', $repoInfo['branches']) : __('None detected', 'wordpress-git-connector')); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="wgc-panel">
                        <h2><?php esc_html_e('Recent Activity', 'wordpress-git-connector'); ?></h2>
                        <?php $activityLog = $this->get_activity_log(); ?>
                        <?php if ($activityLog): ?>
                            <div class="wgc-activity-list">
                                <?php foreach ($activityLog as $entry): ?>
                                    <div class="wgc-activity-item">
                                        <div class="wgc-activity-head">
                                            <span
                                                class="wgc-activity-badge <?php echo !empty($entry['success']) ? 'is-success' : 'is-error'; ?>">
                                                <?php echo !empty($entry['success']) ? esc_html__('Success', 'wordpress-git-connector') : esc_html__('Error', 'wordpress-git-connector'); ?>
                                            </span>
                                            <strong><?php echo esc_html($entry['title'] ?? __('Git Action', 'wordpress-git-connector')); ?></strong>
                                            <span class="wgc-activity-time"><?php echo esc_html($entry['time'] ?? ''); ?></span>
                                        </div>
                                        <div class="wgc-activity-message"><?php echo esc_html($entry['message'] ?? ''); ?></div>
                                        <?php if (!empty($entry['meta'])): ?>
                                            <div class="wgc-activity-meta"><?php echo esc_html($entry['meta']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($entry['output'])): ?>
                                            <pre class="wgc-output"><?php echo esc_html($entry['output']); ?></pre>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="description"><?php esc_html_e('No Git activity recorded yet.', 'wordpress-git-connector'); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="wgc-panel">
                        <h2><?php esc_html_e('Backup Files', 'wordpress-git-connector'); ?></h2>
                        <?php $backupFiles = $this->get_backup_files($settings); ?>
                        <?php if ($backupFiles): ?>
                            <table class="widefat striped wgc-summary-table">
                                <tbody>
                                    <?php foreach ($backupFiles as $backupFile): ?>
                                        <tr>
                                            <td><?php echo esc_html($backupFile['name']); ?></td>
                                            <td><?php echo esc_html(size_format((int) $backupFile['size'])); ?></td>
                                            <td>
                                                <a class="button button-secondary"
                                                    href="<?php echo esc_url($this->get_backup_download_url($backupFile['path'])); ?>">
                                                    <?php esc_html_e('Download', 'wordpress-git-connector'); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="description">
                                <?php esc_html_e('Click Download and your browser will handle the destination using the device’s normal download behavior.', 'wordpress-git-connector'); ?>
                            </p>
                        <?php else: ?>
                            <p class="description">
                                <?php esc_html_e('No backup files have been created yet.', 'wordpress-git-connector'); ?></p>
                        <?php endif; ?>
                    </div>

                </aside>
            </div>
        </div>
        <?php
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
                <?php foreach ($actions as $action): ?>
                    <button type="submit" class="button button-secondary wgc-secondary-button" name="wgc_action"
                        value="<?php echo esc_attr($action['action']); ?>">
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

    private function render_remote_update_form(string $remoteUrl): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wgc_git_action'); ?>
            <input type="hidden" name="action" value="wgc_git_action">
            <input type="hidden" name="wgc_action" value="set_remote">
            <p>
                <label
                    for="wgc_remote_update"><strong><?php esc_html_e('Update Remote URL', 'wordpress-git-connector'); ?></strong></label><br>
                <input id="wgc_remote_update" name="remote_url" type="text" class="regular-text code"
                    value="<?php echo esc_attr($remoteUrl); ?>">
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

    private function record_activity(string $action, array $result, array $settings): void
    {
        $log = get_option(self::LOG_OPTION_KEY, []);
        if (!is_array($log)) {
            $log = $log !== '' ? [
                [
                    'title' => __('Previous Output', 'wordpress-git-connector'),
                    'message' => __('Stored output from an older plugin version.', 'wordpress-git-connector'),
                    'output' => (string) $log,
                    'success' => true,
                    'time' => current_time('mysql'),
                    'meta' => '',
                ]
            ] : [];
        }

        array_unshift($log, [
            'title' => $this->get_action_label($action),
            'message' => (string) ($result['message'] ?? __('Git action completed.', 'wordpress-git-connector')),
            'output' => trim((string) ($result['output'] ?? '')),
            'success' => !empty($result['success']),
            'time' => current_time('mysql'),
            'meta' => $this->build_activity_meta($settings),
        ]);

        update_option(self::LOG_OPTION_KEY, array_slice($log, 0, 12), false);
    }

    private function get_activity_log(): array
    {
        $log = get_option(self::LOG_OPTION_KEY, []);
        return is_array($log) ? $log : [];
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
