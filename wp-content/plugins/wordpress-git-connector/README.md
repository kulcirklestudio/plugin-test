# WordPress Git Connector

WordPress admin plugin for managing a Git repository without opening Bash or another CLI tool.

## Features

- Connect an existing local repository by absolute path
- Initialize a new local repository from a plain folder
- Clone an SSH repository to a local folder
- Use an SSH private key path instead of username/password prompts
- Fetch, pull, push, and view repository status
- Stage all changes and create commits
- Add or update the `origin` remote
- See and switch the active branch
- Create and delete branches
- Show the latest Git command output inside wp-admin

## Installation

1. Copy the `wordpress-git-connector` folder into `wp-content/plugins/`
2. Activate **WordPress Git Connector** in WordPress admin
3. Open **Git Connector** from the admin menu
4. Enter the local repo path, SSH remote URL, and SSH key path
5. Save settings, then use `Initialize Local Repo`, `Connect Existing Repo`, or `Clone SSH Repo`

## Requirements

- Git installed on the server running WordPress
- SSH available on the server
- The web server user must have permission to access the local repo path and SSH key

## Notes

- The plugin runs Git commands on the server using PHP `proc_open()`
- The SSH key is referenced by file path and is not uploaded into WordPress
- Branch deletion uses `git branch -D`
