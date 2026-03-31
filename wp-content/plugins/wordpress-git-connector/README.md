# WordPress Git Connector

Manage a local Git repository from WordPress admin without opening Bash or another CLI tool.

## What this plugin does

- Connect an existing local repository by absolute path
- Initialize a plain folder as a Git repository
- Clone an SSH repository into a local path
- Use an SSH private key file instead of Git username/password prompts
- Fetch, pull, push, stage, commit, merge, switch branches, create branches, and delete branches
- Protect the configured main branch from direct commit/push by default
- Show diagnostics, recent activity, backups, and `.gitignore` controls in wp-admin

## User Guide

### Requirements

- WordPress admin access with `manage_options`
- Git installed on the same machine that runs WordPress
- SSH installed on the same machine
- PHP able to run external processes
- A readable private SSH key for the remote host

### Install

1. Copy `wordpress-git-connector` into `wp-content/plugins/`
2. Activate **WordPress Git Connector**
3. Open **Git Connector** from the WordPress admin menu

### Initial setup

Fill in these settings first:

- `Git Binary`: leave empty or `git` to auto-detect, or enter the full path to `git.exe`
- `Local Repo Path`: the folder you want to manage
- `SSH Remote URL`: for example `git@github.com:owner/repo.git`
- `SSH Key Path`: full path to your private key file
- `Default Branch`: the main branch name, usually `main`

Then choose one setup path:

- `Initialize Local Repo`: use this when the folder exists but is not yet a Git repository
- `Connect Existing Repo`: use this when the folder already contains a `.git` directory
- `Clone SSH Repo`: use this when you want WordPress to clone the remote repository into the local path

### Daily workflow

1. Use `Fetch` or `Pull` to sync remote changes
2. Make file changes in the local project
3. Use `Stage All Changes`
4. Enter a commit message and use `Commit Changes`
5. Use `Push`
6. For safer work, create a feature branch, commit there, then merge back into the main branch

### Main branch protection

- The configured `Default Branch` is treated as the protected main branch
- Direct commit and push on that branch are blocked by default
- Enable `Allow direct commit and push actions when the active branch is the configured main branch` only if you want to bypass that protection

### Diagnostics and SSH checks

Use `Run Full Diagnostics` to verify:

- Git binary
- SSH binary
- local path exists
- local path is writable
- `.git` exists
- remote URL is set
- SSH key exists and is readable
- GitHub SSH handshake works

If the SSH handshake fails because the key file permissions are too open on Windows, tighten the file ACL so only the current Windows user can read the private key.

### Backups

Branch deletion can create:

- a backup Git branch
- a downloadable patch file

Backup patch files appear in the `Backup Files` panel and are downloaded using the browser's normal download behavior.

## Developer Guide

### File structure

```text
wordpress-git-connector/
|-- assets/
|   |-- admin.css
|   `-- admin.js
|-- includes/
|   |-- class-wordpress-git-connector.php
|   `-- views/
|       `-- admin-page.php
|-- wordpress-git-connector.php
`-- README.md
```

### Responsibility split

- `wordpress-git-connector.php`
  - plugin bootstrap
  - loads the main class
- `includes/class-wordpress-git-connector.php`
  - WordPress hooks
  - settings
  - Git command execution
  - diagnostics
  - activity logging
  - branch and backup actions
- `includes/views/admin-page.php`
  - admin page markup only
  - receives prepared variables from `render_admin_page()`
- `assets/admin.css`
  - admin layout and color palette
- `assets/admin.js`
  - lightweight browser behavior such as confirm prompts and submit loading states

### Admin page flow

`render_admin_page()` prepares data, then loads the view:

- settings
- notice
- repo info
- UI state
- diagnostics
- `.gitignore` contents
- activity filters
- filtered activity log

That keeps data gathering in PHP and the markup in a dedicated view file.

### Action flow

Most button actions post to:

- `admin-post.php?action=wgc_git_action`

The plugin then routes the request through `handle_git_action()` and records the result in the activity log.

### Git execution notes

- Git commands run on the server through PHP process execution
- SSH auth uses `GIT_SSH_COMMAND` and the configured private key path
- Remote actions are blocked when the repo, remote URL, or SSH key state is invalid

### Safe extension points

Low-risk additions that fit the current structure:

- add new admin cards in `includes/views/admin-page.php`
- add new frontend behavior in `assets/admin.js`
- add new diagnostics checks in the class
- add new Git actions in `handle_git_action()` with dedicated private methods

### Development cautions

- This plugin manages a live local path, so switching branches can change files that WordPress is currently serving
- If the plugin is installed inside the same repository it manages, branch switching can change the running site immediately
- For the safest setup, keep the controller plugin in a stable WordPress install and manage a separate working-copy path

## UX improvements worth doing next

Low-risk improvements that would keep the UI smooth and understandable:

- add a small changed-files preview before commit instead of only after staging
- add a branch search field when the branch list grows
- add a compact recent-commit list in the sidebar
- add clearer empty states for first-time setup and missing remote configuration
- add optional auto-refresh after actions like branch switch or push
- add a lightweight diff preview for staged files
