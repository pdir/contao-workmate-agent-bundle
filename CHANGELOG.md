# Changelog

[//]: <> (
Types of changes
    Added for new features.
    Changed for changes in existing functionality.
    Deprecated for soon-to-be removed features.
    Removed for now removed features.
    Fixed for any bug fixes.
    Security in case of vulnerabilities.
)

## [1.0.1](https://github.com/pdir/contao-workmate-agent-bundle/tree/1.0.1) – 2026-11-10

**Check script**

- [Fixed] The commit author is read like Contao reads the environment: only `.env.local.php` if it exists, otherwise `.env` and `.env.local`, and `.env.local` only if `.env` exists
- [Added] Checks that `.env` exists, Contao ignores `.env.local` without it; `--fix` creates an empty `.env`
- [Added] Warning if `.env.local.php` exists, changes in `.env.local` need `composer dump-env prod` then

## [1.0.0](https://github.com/pdir/contao-workmate-agent-bundle/tree/1.0.0) – 2026-11-10

Initial release.

**Agent**

- [Added] Endpoint `POST /_workmate/agent` for Workmate with the actions `check` and `commit`, JSON response `{"ok": true, …}` or `{"ok": false, "error": "…"}`
- [Added] `commit` creates a database backup with the Contao backup manager (like `contao:backup:create`, uncompressed), writes it to `_db/latest.sql`, commits it with `composer.json` and `composer.lock` and pushes the commit (`git push origin HEAD`); other changes of the working tree are not committed
- [Added] No commit if the dump, `composer.json` and `composer.lock` are unchanged
- [Added] `check` returns the agent version, branch, remote, whether the working tree is clean, whether `_db/latest.sql` is ignored and the commit author
- [Added] Support for Contao 4.13 and 5.x (backup directory and virtual filesystem) and PHP 7.4 to 8.x
- [Added] Works on a branch without commits (after `git init`); a detached HEAD is rejected before the backup is created

**Configuration**

- [Added] Required env variables `WORKMATE_AGENT_AUTHOR_NAME` and `WORKMATE_AGENT_AUTHOR_EMAIL` for the author of the commits, so every agency commits under its own name; they take precedence over the git config of the server, a missing or invalid author is reported before the backup is created
- [Added] Optional env variables `WORKMATE_AGENT_TOKEN_HASH`, `WORKMATE_AGENT_GIT` (git binary) and `WORKMATE_AGENT_SSH_KEY` (key for the push)
- [Added] Installation by Workmate over the Contao Manager API, the SHA-256 hash of the token is stored in `composer.json` (`extra.workmate.agent-token-hash`)

**Check script**

- [Added] `vendor/bin/workmate-agent-check` checks the project, PHP, Git and GitLab without changing anything (only `git push --dry-run`), with `--url` it also calls the agent over HTTP
- [Added] Option `--fix` sets the rights of `contao-manager/auth.json` and the SSH key to 600, only if the file belongs to the current user
- [Added] Checks the commit author from the environment, `.env` and `.env.local`
- [Added] Hints for a detached HEAD (branches of the checked out commit, `git switch <branch>`) and for a branch without commits whose remote branch already has commits (`git reset origin/<branch>`)
- [Added] Only the directories in `.git` have to be writable, the read-only object files of git are no longer reported

**Security**

- [Added] Only requests with the header `X-Workmate-Token` matching the SHA-256 hash are accepted (`hash_equals`), the token itself is not stored in the project
- [Added] git is started with `proc_open` and an argument list, without a shell; control characters are removed from the commit message
- [Added] Responses are sent with `Cache-Control: no-store, private` and `X-Robots-Tag: noindex`
