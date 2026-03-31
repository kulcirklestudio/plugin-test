<?php
?>
<div class="wrap wgc-admin">
            <div class="wgc-hero">
                <div>
                    <h1><?php esc_html_e('WordPress Git Connector', 'wordpress-git-connector'); ?></h1>
                    <p><?php esc_html_e('Manage local repositories, branch workflows, merges, commits, and SSH remotes from a cleaner WordPress admin interface.', 'wordpress-git-connector'); ?></p>
                </div>
                <div class="wgc-hero-meta">
                    <span class="wgc-pill"><?php esc_html_e('Current Branch', 'wordpress-git-connector'); ?>: <?php echo esc_html($repoInfo['active_branch'] ?: __('Unknown', 'wordpress-git-connector')); ?></span>
                    <span class="wgc-pill wgc-pill-accent"><?php esc_html_e('Main Branch', 'wordpress-git-connector'); ?>: <?php echo esc_html($settings['default_branch'] ?: __('Not set', 'wordpress-git-connector')); ?></span>
                </div>
            </div>

            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible wgc-notice">
                    <p><strong><?php echo esc_html($notice['message']); ?></strong></p>
                    <?php if (!empty($notice['output'])) : ?>
                        <pre class="wgc-output"><?php echo esc_html($notice['output']); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="wgc-layout">
                <div class="wgc-main">
                    <?php if ($uiState['warnings']) : ?>
                        <div class="wgc-panel wgc-warning-panel">
                            <h2><?php esc_html_e('Workflow Warnings', 'wordpress-git-connector'); ?></h2>
                            <div class="wgc-warning-list">
                                <?php foreach ($uiState['warnings'] as $warning) : ?>
                                    <div class="wgc-warning-item"><?php echo esc_html($warning); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="wgc-panel wgc-panel-settings">
                        <div class="wgc-panel-head">
                            <h2><?php esc_html_e('Connection Settings', 'wordpress-git-connector'); ?></h2>
                            <p><?php esc_html_e('Define the local repository path, SSH remote, Git binary, and branch protection rules.', 'wordpress-git-connector'); ?></p>
                        </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-settings-form">
                        <?php wp_nonce_field('wgc_save_settings'); ?>
                        <input type="hidden" name="action" value="wgc_save_settings">

                        <table class="form-table" role="presentation">
                            <tbody>
                            <tr>
                                <th scope="row"><label for="wgc_git_binary"><?php esc_html_e('Git Binary', 'wordpress-git-connector'); ?></label></th>
                                <td>
                                    <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[git_binary]" id="wgc_git_binary" type="text" class="regular-text" value="<?php echo esc_attr($settings['git_binary']); ?>">
                                    <p class="description"><?php esc_html_e('The plugin tries to detect git.exe automatically. Only enter a full path if detection fails.', 'wordpress-git-connector'); ?></p>
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
                                    <td>
                                        <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_branch]" id="wgc_default_branch" type="text" class="regular-text" value="<?php echo esc_attr($settings['default_branch']); ?>">
                                        <p class="description"><?php esc_html_e('This is treated as the main protected branch. Users should normally work on another branch and merge into this branch.', 'wordpress-git-connector'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="wgc_author_name"><?php esc_html_e('Commit Author Name', 'wordpress-git-connector'); ?></label></th>
                                    <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[author_name]" id="wgc_author_name" type="text" class="regular-text" value="<?php echo esc_attr($settings['author_name']); ?>"></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="wgc_author_email"><?php esc_html_e('Commit Author Email', 'wordpress-git-connector'); ?></label></th>
                                    <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[author_email]" id="wgc_author_email" type="email" class="regular-text" value="<?php echo esc_attr($settings['author_email']); ?>"></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Direct Main Branch Changes', 'wordpress-git-connector'); ?></th>
                                    <td>
                                        <label>
                                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_direct_main_changes]" type="checkbox" value="1" <?php checked($settings['allow_direct_main_changes'], '1'); ?>>
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
                        <p><?php esc_html_e('Work from top to bottom: set up the repository, sync branches, create commits, then manage merges and cleanup.', 'wordpress-git-connector'); ?></p>
                    </div>
                    <div class="wgc-workflow">
                        <?php $this->render_action_card(
                            __('Repository Setup', 'wordpress-git-connector'),
                            __('Use these actions to initialize a new local repository, connect an existing one, or verify the SSH remote connection.', 'wordpress-git-connector'),
                            __('Step 1', 'wordpress-git-connector'),
                            function () use ($settings, $uiState) { ?>
                            <?php $this->render_action_button_group([
                                ['action' => 'initialize_repo', 'label' => __('Initialize Local Repo', 'wordpress-git-connector'), 'confirm' => __('Initialize a Git repository in the configured local path?', 'wordpress-git-connector')],
                                ['action' => 'connect_repo', 'label' => __('Connect Existing Repo', 'wordpress-git-connector'), 'disabled' => !$uiState['path_exists'], 'disabled_reason' => __('Local path must exist before you can connect it.', 'wordpress-git-connector')],
                                ['action' => 'clone_repo', 'label' => __('Clone SSH Repo', 'wordpress-git-connector'), 'confirm' => __('Clone the configured SSH repository into the configured local path?', 'wordpress-git-connector'), 'disabled' => !$uiState['can_clone'], 'disabled_reason' => __('Set a valid SSH remote and local path before cloning.', 'wordpress-git-connector')],
                                ['action' => 'test_connection', 'label' => __('Test Connection', 'wordpress-git-connector')],
                                ['action' => 'test_remote', 'label' => __('Test Remote SSH', 'wordpress-git-connector'), 'disabled' => !$uiState['has_remote'], 'disabled_reason' => __('Configure an SSH remote URL first.', 'wordpress-git-connector')],
                            ]); ?>
                            <?php $this->render_remote_update_form($settings['remote_url']); ?>
                        <?php }); ?>

                        <?php $this->render_action_card(
                            __('Sync And Remote', 'wordpress-git-connector'),
                            __('Fetch updates, import remote branches, pull the active branch, or push your local commits to the remote repository.', 'wordpress-git-connector'),
                            __('Step 2', 'wordpress-git-connector'),
                            function () use ($uiState) { ?>
                            <?php $this->render_action_button_group([
                                ['action' => 'fetch', 'label' => __('Fetch', 'wordpress-git-connector'), 'disabled' => !$uiState['can_run_remote'], 'disabled_reason' => __('Remote Git actions require a repo, SSH remote, and readable SSH key.', 'wordpress-git-connector')],
                                ['action' => 'sync_remote_branches', 'label' => __('Import Remote Branches', 'wordpress-git-connector'), 'disabled' => !$uiState['can_run_remote'], 'disabled_reason' => __('Remote branch import requires a working SSH remote configuration.', 'wordpress-git-connector')],
                                ['action' => 'pull', 'label' => __('Pull', 'wordpress-git-connector'), 'disabled' => !$uiState['can_run_remote'], 'disabled_reason' => __('Pull requires a connected repository and working SSH remote.', 'wordpress-git-connector')],
                                ['action' => 'push', 'label' => __('Push', 'wordpress-git-connector'), 'confirm' => __('Push committed changes to the remote repository now?', 'wordpress-git-connector'), 'disabled' => !$uiState['can_run_remote'], 'disabled_reason' => __('Push requires a connected repository and working SSH remote.', 'wordpress-git-connector')],
                                ['action' => 'status', 'label' => __('Refresh Status', 'wordpress-git-connector'), 'disabled' => !$uiState['has_repo'], 'disabled_reason' => __('Initialize or connect a repository first.', 'wordpress-git-connector')],
                            ]); ?>
                        <?php }); ?>

                        <?php $this->render_action_card(
                            __('Commit Changes', 'wordpress-git-connector'),
                            __('Stage modified files first, then create a commit with a message describing the changes.', 'wordpress-git-connector'),
                            __('Step 3', 'wordpress-git-connector'),
                            function () use ($uiState) { ?>
                            <?php $this->render_action_button_group([
                                ['action' => 'add_all', 'label' => __('Stage All Changes', 'wordpress-git-connector'), 'disabled' => !$uiState['has_repo'], 'disabled_reason' => __('Initialize or connect a repository first.', 'wordpress-git-connector')],
                            ]); ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-stack-form">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="commit">
                                <p>
                                    <label for="wgc_commit_message"><strong><?php esc_html_e('Commit Message', 'wordpress-git-connector'); ?></strong></label><br>
                                    <textarea id="wgc_commit_message" name="commit_message" rows="4" class="large-text" required></textarea>
                                </p>
                                <?php $this->render_submit_button(__('Commit Changes', 'wordpress-git-connector'), [
                                    'class' => 'secondary',
                                    'disabled' => !$uiState['has_repo'],
                                    'confirm' => __('Create a new commit with the staged changes?', 'wordpress-git-connector'),
                                ]); ?>
                            </form>
                        <?php }); ?>

                        <?php $this->render_action_card(
                            __('Branch Management', 'wordpress-git-connector'),
                            __('Switch branches, create new ones, merge another branch into the active branch, or delete branches you no longer need.', 'wordpress-git-connector'),
                            __('Step 4', 'wordpress-git-connector'),
                            function () use ($repoInfo, $settings, $uiState) { ?>
                            <div class="wgc-branch-overview">
                                <span class="wgc-branch-badge is-active"><?php echo esc_html($repoInfo['active_branch'] ?: __('No active branch', 'wordpress-git-connector')); ?></span>
                                <span class="wgc-branch-badge is-main"><?php echo esc_html(($settings['default_branch'] ?: __('Not set', 'wordpress-git-connector')) . ' ' . __('main', 'wordpress-git-connector')); ?></span>
                            </div>
                            <?php if (!empty($repoInfo['active_branch']) && $repoInfo['active_branch'] === $settings['default_branch'] && $settings['allow_direct_main_changes'] !== '1') : ?>
                                <p class="wgc-inline-warning">
                                    <?php esc_html_e('Direct commit and push on the main branch are currently blocked. Create or switch to a working branch, then merge it into the main branch.', 'wordpress-git-connector'); ?>
                                </p>
                            <?php endif; ?>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-stack-form">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="checkout_branch">
                                <p>
                                    <label for="wgc_active_branch"><strong><?php esc_html_e('Active Branch', 'wordpress-git-connector'); ?></strong></label><br>
                                    <select id="wgc_active_branch" name="active_branch">
                                        <?php $this->render_branch_options($repoInfo['branches'], $settings['default_branch'], $repoInfo['active_branch']); ?>
                                    </select>
                                </p>
                                <?php $this->render_submit_button(__('Switch Branch', 'wordpress-git-connector'), [
                                    'class' => 'secondary',
                                    'disabled' => !$uiState['has_branches'],
                                    'confirm' => __('Switch to the selected branch now?', 'wordpress-git-connector'),
                                ]); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-stack-form">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="create_branch">
                                <p>
                                    <label for="wgc_branch_name"><strong><?php esc_html_e('New Branch Name', 'wordpress-git-connector'); ?></strong></label><br>
                                    <input id="wgc_branch_name" name="branch_name" type="text" class="regular-text" required>
                                </p>
                                <?php $this->render_submit_button(__('Create Branch', 'wordpress-git-connector'), [
                                    'class' => 'secondary',
                                    'disabled' => !$uiState['has_repo'],
                                ]); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-stack-form">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="merge_into_active">
                                <p>
                                    <label for="wgc_source_branch"><strong><?php esc_html_e('Merge Branch Into Active Branch', 'wordpress-git-connector'); ?></strong></label><br>
                                    <select id="wgc_source_branch" name="source_branch">
                                        <?php $this->render_branch_options($repoInfo['branches'], $settings['default_branch'], '', $repoInfo['active_branch']); ?>
                                    </select>
                                </p>
                                <p class="description"><?php esc_html_e('The selected branch will be merged into the currently active branch. Use this to bring working branch changes into the main branch. If conflicts happen, Git output will be shown below.', 'wordpress-git-connector'); ?></p>
                                <?php $this->render_submit_button(__('Merge Into Active Branch', 'wordpress-git-connector'), [
                                    'class' => 'secondary',
                                    'disabled' => !$uiState['has_multiple_branches'],
                                    'confirm' => __('Merge the selected branch into the active branch?', 'wordpress-git-connector'),
                                ]); ?>
                            </form>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-stack-form">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="delete_branch">
                                <p>
                                    <label for="wgc_delete_branch"><strong><?php esc_html_e('Delete Branch', 'wordpress-git-connector'); ?></strong></label><br>
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
                                <p class="description"><?php esc_html_e('The active branch cannot be deleted. If backup is enabled, a branch named backup/<branch>-YYYYmmdd-HHMMSS will be created first.', 'wordpress-git-connector'); ?></p>
                                <?php $this->render_submit_button(__('Delete Branch', 'wordpress-git-connector'), [
                                    'class' => 'delete',
                                    'disabled' => !$uiState['has_deletable_branch'],
                                    'confirm' => __('Delete the selected branch? This cannot be undone unless you create a backup.', 'wordpress-git-connector'),
                                ]); ?>
                            </form>
                        <?php }); ?>

                        <?php $this->render_action_card(
                            __('Ignore Rules', 'wordpress-git-connector'),
                            __('Review or edit .gitignore and quickly add the most common WordPress exclusions to keep generated files out of Git.', 'wordpress-git-connector'),
                            __('Step 5', 'wordpress-git-connector'),
                            function () use ($gitignoreContents, $uiState) { ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-stack-form">
                                <?php wp_nonce_field('wgc_git_action'); ?>
                                <input type="hidden" name="action" value="wgc_git_action">
                                <input type="hidden" name="wgc_action" value="save_gitignore">
                                <p>
                                    <label for="wgc_gitignore_contents"><strong><?php esc_html_e('.gitignore Contents', 'wordpress-git-connector'); ?></strong></label><br>
                                    <textarea id="wgc_gitignore_contents" name="gitignore_contents" rows="12" class="large-text code"><?php echo esc_textarea($gitignoreContents); ?></textarea>
                                </p>
                                <div class="wgc-inline-actions">
                                    <?php $this->render_submit_button(__('Save .gitignore', 'wordpress-git-connector'), [
                                        'class' => 'secondary',
                                        'disabled' => !$uiState['path_exists'],
                                    ]); ?>
                                    <button type="submit" class="button button-secondary wgc-secondary-button" name="wgc_action" value="apply_gitignore_suggestions" <?php disabled(!$uiState['path_exists']); ?>>
                                        <?php esc_html_e('Add Suggested WordPress Exclusions', 'wordpress-git-connector'); ?>
                                    </button>
                                </div>
                                <div class="wgc-suggestion-list">
                                    <?php foreach ($this->get_gitignore_suggestions() as $suggestion) : ?>
                                        <code><?php echo esc_html($suggestion); ?></code>
                                    <?php endforeach; ?>
                                </div>
                            </form>
                        <?php }); ?>
                    </div>
                </div>

                <aside class="wgc-sidebar">
                    <div class="wgc-panel">
                    <h2><?php esc_html_e('Repository Summary', 'wordpress-git-connector'); ?></h2>
                    <table class="widefat striped wgc-summary-table">
                        <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Local Path', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($settings['local_path'] ?: __('Not configured', 'wordpress-git-connector')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Repo Health', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($uiState['has_repo'] ? __('Connected', 'wordpress-git-connector') : __('Not connected', 'wordpress-git-connector')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Remote Health', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($uiState['has_remote'] ? __('Remote configured', 'wordpress-git-connector') : __('Remote missing', 'wordpress-git-connector')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('SSH Health', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($uiState['has_ssh_key'] ? __('SSH key ready', 'wordpress-git-connector') : __('SSH key missing or unreadable', 'wordpress-git-connector')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Working Tree', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($uiState['working_tree_dirty'] ? __('Has local changes', 'wordpress-git-connector') : __('Clean', 'wordpress-git-connector')); ?></td>
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
                            <td><strong><?php esc_html_e('Upstream', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($repoInfo['upstream_branch'] ?: __('Not configured', 'wordpress-git-connector')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Ahead / Behind', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($repoInfo['upstream_branch'] ? sprintf('%d ahead, %d behind', (int) $repoInfo['ahead_by'], (int) $repoInfo['behind_by']) : __('Not available', 'wordpress-git-connector')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Branches', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($repoInfo['branches'] ? implode(', ', $repoInfo['branches']) : __('None detected', 'wordpress-git-connector')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Latest Commit', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html($repoInfo['latest_commit'] ?: __('No commits yet', 'wordpress-git-connector')); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('File Status', 'wordpress-git-connector'); ?></strong></td>
                            <td><?php echo esc_html(sprintf(
                                '%d staged, %d unstaged, %d untracked',
                                (int) $repoInfo['status_counts']['staged'],
                                (int) $repoInfo['status_counts']['unstaged'],
                                (int) $repoInfo['status_counts']['untracked']
                            )); ?></td>
                        </tr>
                        </tbody>
                    </table>
                    </div>

                    <div class="wgc-panel">
                    <h2><?php esc_html_e('Backup Files', 'wordpress-git-connector'); ?></h2>
                    <?php $backupFiles = $this->get_backup_files($settings); ?>
                    <?php if ($backupFiles) : ?>
                        <table class="widefat striped wgc-summary-table">
                            <tbody>
                            <?php foreach ($backupFiles as $backupFile) : ?>
                                <tr>
                                    <td><?php echo esc_html($backupFile['name']); ?></td>
                                    <td><?php echo esc_html(size_format((int) $backupFile['size'])); ?></td>
                                    <td>
                                        <a class="button button-secondary" href="<?php echo esc_url($this->get_backup_download_url($backupFile['path'])); ?>">
                                            <?php esc_html_e('Download', 'wordpress-git-connector'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="description"><?php esc_html_e("Click Download and your browser will handle the destination using the device's normal download behavior.", 'wordpress-git-connector'); ?></p>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('No backup files have been created yet.', 'wordpress-git-connector'); ?></p>
                    <?php endif; ?>
                    </div>

                    <div class="wgc-panel">
                    <h2><?php esc_html_e('Environment Readiness', 'wordpress-git-connector'); ?></h2>
                    <div class="wgc-diagnostics-list">
                        <?php foreach ($diagnostics['checks'] as $check) : ?>
                            <div class="wgc-diagnostic-item">
                                <span class="wgc-activity-badge <?php echo $check['status'] === 'success' ? 'is-success' : 'is-error'; ?>">
                                    <?php echo esc_html(ucfirst($check['status'])); ?>
                                </span>
                                <div>
                                    <strong><?php echo esc_html($check['label']); ?></strong>
                                    <div class="wgc-activity-meta"><?php echo esc_html($check['message']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgc-stack-form">
                        <?php wp_nonce_field('wgc_git_action'); ?>
                        <input type="hidden" name="action" value="wgc_git_action">
                        <input type="hidden" name="wgc_action" value="run_diagnostics">
                        <?php $this->render_submit_button(__('Run Full Diagnostics', 'wordpress-git-connector'), [
                            'class' => 'secondary',
                            'confirm' => __('Run the environment diagnostics and test the GitHub SSH handshake now?', 'wordpress-git-connector'),
                        ]); ?>
                    </form>
                    </div>

                    <div class="wgc-panel">
                    <h2><?php esc_html_e('Recent Activity', 'wordpress-git-connector'); ?></h2>
                    <form method="get" class="wgc-log-filters">
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
                        <select name="wgc_log_status">
                            <option value="all" <?php selected($statusFilter, 'all'); ?>><?php esc_html_e('All statuses', 'wordpress-git-connector'); ?></option>
                            <option value="success" <?php selected($statusFilter, 'success'); ?>><?php esc_html_e('Success only', 'wordpress-git-connector'); ?></option>
                            <option value="error" <?php selected($statusFilter, 'error'); ?>><?php esc_html_e('Errors only', 'wordpress-git-connector'); ?></option>
                        </select>
                        <select name="wgc_log_action">
                            <option value="all"><?php esc_html_e('All actions', 'wordpress-git-connector'); ?></option>
                            <?php foreach ($activityActions as $actionKey => $actionLabel) : ?>
                                <option value="<?php echo esc_attr($actionKey); ?>" <?php selected($actionFilter, $actionKey); ?>><?php echo esc_html($actionLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-secondary wgc-secondary-button"><?php esc_html_e('Filter', 'wordpress-git-connector'); ?></button>
                        <a class="button button-secondary wgc-secondary-button" href="<?php echo esc_url(wp_nonce_url(add_query_arg([
                            'action' => 'wgc_export_activity',
                            'status' => $statusFilter,
                            'action_filter' => $actionFilter,
                        ], admin_url('admin-post.php')), 'wgc_export_activity')); ?>"><?php esc_html_e('Export Logs', 'wordpress-git-connector'); ?></a>
                    </form>
                    <?php if ($activityLog) : ?>
                        <div class="wgc-activity-list">
                            <?php foreach ($activityLog as $entry) : ?>
                                <div class="wgc-activity-item">
                                    <div class="wgc-activity-head">
                                        <span class="wgc-activity-badge <?php echo !empty($entry['success']) ? 'is-success' : 'is-error'; ?>">
                                            <?php echo !empty($entry['success']) ? esc_html__('Success', 'wordpress-git-connector') : esc_html__('Error', 'wordpress-git-connector'); ?>
                                        </span>
                                        <strong><?php echo esc_html($entry['title'] ?? __('Git Action', 'wordpress-git-connector')); ?></strong>
                                        <span class="wgc-activity-time"><?php echo esc_html($entry['time'] ?? ''); ?></span>
                                    </div>
                                    <div class="wgc-activity-message"><?php echo esc_html($entry['message'] ?? ''); ?></div>
                                    <?php if (!empty($entry['meta'])) : ?>
                                        <div class="wgc-activity-meta"><?php echo esc_html($entry['meta']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['duration_ms'])) : ?>
                                        <div class="wgc-activity-meta"><?php echo esc_html(sprintf(__('Duration: %d ms', 'wordpress-git-connector'), (int) $entry['duration_ms'])); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['output'])) : ?>
                                        <pre class="wgc-output"><?php echo esc_html($entry['output']); ?></pre>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e('No Git activity recorded yet.', 'wordpress-git-connector'); ?></p>
                    <?php endif; ?>
                    </div>
                </aside>
            </div>
        </div>
        <?php
