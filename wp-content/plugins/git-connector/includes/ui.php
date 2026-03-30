<?php
function render_git_settings_page()
{
    $path = get_option('git_plugin_local_path', '');

    // ======================
    // TEST REPO
    // ======================
    if (
        isset($_POST['test_git_repo']) &&
        wp_verify_nonce($_POST['test_git_repo_nonce'], 'test_git_repo_action')
    ) {
        if (!$path) {
            echo '<div class="notice notice-error"><p>No path set</p></div>';
        } else {
            $result = run_git_command($path, 'status');

            echo '<div class="notice ' . ($result['status'] === 0 ? 'notice-success' : 'notice-error') . '"><pre>';
            echo esc_html(implode("\n", $result['output']));
            echo '</pre></div>';
        }
    }

    // ======================
    // SWITCH BRANCH (SAFE)
    // ======================
    if (
        isset($_POST['switch_branch']) &&
        wp_verify_nonce($_POST['select_branch_nonce'], 'select_branch_action')
    ) {
        $branch = sanitize_text_field($_POST['branch']);

        // 🔴 Check for uncommitted changes
        $status_check = run_git_command($path, 'status --porcelain');

        if (!empty($status_check['output'])) {
            echo '<div class="notice notice-error"><p>Uncommitted changes exist. Commit or stash before switching branch.</p></div>';
        } else {
            $result = run_git_command($path, 'checkout ' . escapeshellarg($branch));

            echo '<div class="notice ' . ($result['status'] === 0 ? 'notice-success' : 'notice-error') . '"><pre>';
            echo esc_html(implode("\n", $result['output']));
            echo '</pre></div>';
        }
    }

    // ======================
    // LOAD DATA (ONLY IF PATH EXISTS)
    // ======================
    $current_branch = 'N/A';
    $branches = [];
    $remote = [];

    if ($path) {
        $branch_output = run_git_command($path, 'branch --show-current');
        $current_branch = $branch_output['output'][0] ?? 'unknown';

        $branches_result = run_git_command($path, 'branch --format="%(refname:short)"');
        $branches = $branches_result['output'];

        $remote_result = run_git_command($path, 'remote -v');
        $remote = $remote_result['output'];

        $status_result = run_git_command($path, 'status --porcelain');
        $repo_status = $status_result['output'];
    }
    ?>

    <div class="wrap">

        <h1>Git Plugin Settings</h1>

        <?php settings_errors(); ?>

        <div class="repo-info common-wrapper">

            <p class="sec-title">Repository Information</p>

            <!-- REMOTE INFO -->
            <div class="remote-info">

                <h2>Remote</h2>

                <?php if (empty($remote)): ?>
                    <div class="notice notice-warning">
                        <p>No remote repository connected</p>
                    </div>
                <?php else: ?>
                    <pre><?php echo esc_html(implode("\n", $remote)); ?></pre>
                <?php endif; ?>

            </div>

            <!-- SETTINGS FORM -->
            <form method="post" action="options.php">
                <?php settings_fields('git_plugin_settings_group'); ?>

                <table class="form-table">
                    <tr>
                        <th>Local Repository Path</th>
                        <td>
                            <input type="text" name="git_plugin_local_path" value="<?php echo esc_attr($path); ?>"
                                class="regular-text" placeholder="C:\xampp\htdocs\git">
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

        </div>

        <?php if ($path): ?>

            <div class="branch-status common-wrapper">

                <p class="sec-title">Branch Status</p>

                <!-- CURRENT BRANCH + TEST -->
                <div class="current_branch_wrapper">

                    <div><strong>Current Branch:</strong>
                        <p class="active_branch">
                            <?php echo esc_html($current_branch); ?>
                        </p>
                    </div>

                    <form method="post">
                        <?php wp_nonce_field('test_git_repo_action', 'test_git_repo_nonce'); ?>
                        <input type="hidden" name="test_git_repo" value="1">
                        <button type="submit" class="button button-secondary">
                            Debug Repository
                        </button>
                    </form>

                </div>

                <!-- Repo status -->
                <div class="repo-status">

                    <h2>Repository Status</h2>

                    <?php if (empty($repo_status)): ?>
                        <p style="color:green;"><strong>Clean (no changes)</strong></p>
                    <?php else: ?>
                        <p style="color:red;"><strong>Uncommitted Changes:</strong></p>
                        <pre><?php echo esc_html(implode("\n", $repo_status)); ?></pre>
                    <?php endif; ?>

                </div>

            </div>

            <div class="quick-action common-wrapper">

                <p class="sec-title">Quick Action</p>

                <!-- Pull Section -->
                <div class="pull-section">
                    <h2>Pull Latest Changes</h2>

                    <form method="post">
                        <?php wp_nonce_field('git_pull_action', 'git_pull_nonce'); ?>

                        <button type="submit" name="git_pull" class="button button-primary">
                            Pull from Remote
                        </button>
                    </form>
                </div>

                <!-- Commit Section -->
                <div class="commit-section">

                    <h2>Commit Changes</h2>

                    <?php if (empty($repo_status)): ?>
                        <p>No changes to commit</p>
                    <?php else: ?>
                        <form method="post">
                            <?php wp_nonce_field('git_commit_action', 'git_commit_nonce'); ?>

                            <input type="text" name="commit_message" placeholder="Enter commit message" style="width: 300px;"
                                required>

                            <button type="submit" name="git_commit" class="button button-primary">
                                Commit
                            </button>
                        </form>
                    <?php endif; ?>

                </div>

                <!-- Push Section -->
                <div class="push-section">

                    <h2>Push Changes</h2>

                    <form method="post">
                        <?php wp_nonce_field('git_push_action', 'git_push_nonce'); ?>

                        <button type="submit" name="git_push" class="button button-primary">
                            Push to Remote
                        </button>
                    </form>
                </div>

            </div>

            <div class="branch-management common-wrapper">

                <p class="sec-title">Branch Management</p>

                <!-- BRANCH SWITCH -->
                <div class="repo-dd-wrapper">

                    <h2>Switch Branch</h2>

                    <form method="post">
                        <?php wp_nonce_field('select_branch_action', 'select_branch_nonce'); ?>

                        <select name="branch">
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo esc_attr($branch); ?>" <?php selected($branch, $current_branch); ?>>
                                    <?php echo esc_html($branch); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" name="switch_branch" class="button">
                            Switch Branch
                        </button>
                    </form>

                </div>

                <!-- Create Branch Section -->
                <div class="create-branch repo-dd-wrapper">

                    <h2>Create Branch</h2>

                    <form method="post">
                        <?php wp_nonce_field('git_create_branch_action', 'git_create_branch_nonce'); ?>

                        <input type="text" name="new_branch" placeholder="Enter branch name" required>

                        <button type="submit" name="git_create_branch" class="button button-primary">
                            Create & Switch
                        </button>
                    </form>

                </div>

                <!-- Merge Section -->
                <div class="merge-branch">

                    <h2>Merge Branch</h2>

                    <form method="post">
                        <?php wp_nonce_field('git_merge_branch_action', 'git_merge_branch_nonce'); ?>

                        <select name="merge_branch">
                            <?php foreach ($branches as $branch): ?>
                                <?php if ($branch !== $current_branch): ?>
                                    <option value="<?php echo esc_attr($branch); ?>">
                                        <?php echo esc_html($branch); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" name="git_merge_branch" class="button button-primary">
                            Merge into Current Branch
                        </button>
                    </form>

                </div>

                <!-- Delete Branch Section -->
                <div class="delete-branch repo-dd-wrapper">

                    <h2>Delete Branch</h2>

                    <?php
                    $default_branch = git_get_default_branch($path);
                    $current = run_git_command($path, 'branch --show-current');
                    $current_branch = $current['output'][0] ?? '';
                    ?>

                    <form method="post">
                        <?php wp_nonce_field('git_delete_branch_action', 'git_delete_branch_nonce'); ?>

                        <select name="delete_branch">
                            <?php foreach ($branches as $branch): ?>

                                <?php
                                // Skip default branch (if known)
                                if ($default_branch && $branch === $default_branch) {
                                    continue;
                                }

                                ?>

                                <option value="<?php echo esc_attr($branch); ?>">
                                    <?php echo esc_html($branch); ?>
                                </option>

                            <?php endforeach; ?>
                        </select>

                        <label class="delete-remote-chkbx">
                            <input type="checkbox" name="delete_remote" value="1">
                            Also delete from remote
                        </label>

                        <button type="submit" name="git_delete_branch" class="button button-danger">
                            Delete Branch
                        </button>
                    </form>

                    <?php if (!$default_branch): ?>
                        <p style="color:orange;">
                            Default branch not detected. Deletion is limited for safety.
                        </p>
                    <?php endif; ?>

                </div>

            </div>
        <?php endif; ?>

    </div>
    <?php
}