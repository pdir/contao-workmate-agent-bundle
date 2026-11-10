# Contao Workmate agent bundle

Agent of Workmate (the maintenance tool of pdir) for Contao projects (Contao 4.13 and 5.x, PHP 7.4+).

Before and after a Contao update Workmate calls the agent, which

1. creates a database backup with the Contao backup manager (like `contao:backup:create`, uncompressed),
2. writes it to `_db/latest.sql`,
3. commits `_db/latest.sql`, `composer.json` and `composer.lock` (only these files, other changes are not committed)
4. and pushes the commit (`git push origin HEAD`).

## Installation

Workmate installs the agent per click over the Contao Manager API (project → "Agent installieren"):

- `composer.json` gets the SHA-256 hash of the agent token (`extra.workmate.agent-token-hash`), the token itself is
  not stored in the project,
- the package is installed from Packagist with a Manager task (`composer/update`, only this package).

Manual installation:

```bash
composer require pdir/contao-workmate-agent-bundle
```

```json
{
    "extra": {
        "workmate": {"agent-token-hash": "<sha256 of the token>"}
    }
}
```

## Requirements on the server

- the project directory is a Git checkout whose remote can be pushed by the user of the web server (e.g. a GitLab
  deploy key with write access),
- `git` can be run by PHP (`proc_open`),
- `_db/` is not ignored by `.gitignore`.

Env variables (`.env.local`):

| Variable | |
|---|---|
| `WORKMATE_AGENT_AUTHOR_NAME` | **required**, author name of the commits, e.g. `Agentur Beispiel` |
| `WORKMATE_AGENT_AUTHOR_EMAIL` | **required**, author email of the commits, e.g. `noreply@agentur-beispiel.de` |
| `WORKMATE_AGENT_TOKEN_HASH` | optional, hash of the token, takes precedence over `composer.json` |
| `WORKMATE_AGENT_GIT` | optional, git binary, e.g. `/usr/bin/git` |
| `WORKMATE_AGENT_SSH_KEY` | optional, SSH key for the push, e.g. `/home/user/.ssh/id_rsa_gitlab` |

## Check

Checks the project, PHP, Git and GitLab, nothing is changed (only `git push --dry-run`) unless `--fix` is passed. Run
it as the PHP user in the project directory:

```bash
vendor/bin/workmate-agent-check
vendor/bin/workmate-agent-check --fix   # chmod 600 for contao-manager/auth.json and the SSH key (if they belong to the PHP user)
WORKMATE_TOKEN=<token> vendor/bin/workmate-agent-check --url https://www.example.org   # also calls the agent
```

Exit code 0 = ready, 1 = errors.

## API

`POST /_workmate/agent`, header `X-Workmate-Token: <token>`, JSON response `{"ok": true, …}` or
`{"ok": false, "error": "…"}`.

| `action` | |
|---|---|
| `check` | agent version, branch, remote, whether the working tree is clean and `_db/latest.sql` is ignored, commit author (`null` if not set) |
| `commit` | backup, `_db/latest.sql`, commit with `message`, push |

## Tests

```bash
composer install
vendor/bin/phpunit
```

## License

LGPL-3.0-or-later, see [LICENSE](LICENSE).
