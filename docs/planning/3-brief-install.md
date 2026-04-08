## Project Brief: Installation & Update

**Goal**
Give non-Stacks users a frictionless path to get T3 running, and give all users a way to stay current without manual file management. These are the two biggest blockers preventing T3 from standing alone as a platform.

**Constraints**
- Primary install path must require zero terminal knowledge
- `tcms/` app directory and `tcms-data/` are completely separate — updates touch only `tcms/`, never `tcms-data/`
- Update process must be safe to run on a live site
- No assumptions about server environment beyond PHP 8.2+

---

### Part 1: Installation

**Primary path — zip download + web installer**

User downloads a zip, extracts it to their server, visits the URL, and is guided through a wizard. The wizard covers:

- PHP environment check (version, required extensions, file permissions)
- Base URL and directory configuration
- Admin user creation
- License key entry
- Confirmation screen with link to the admin

The wizard writes a `config/environment.php` file and then self-destructs (or locks itself) so it can't be run again.

**Secondary path — Composer**

```bash
composer create-project joeworkman/total-cms my-site
```

Targets developers comfortable with the terminal. Same result as the zip path but without the wizard — config is done via `.env` file and a `tcms install` CLI command.

**ServerAvatar integration**

Work with ServerAvatar to add T3 as a one-click application. This is the highest-leverage install path for BSH customers specifically — "one-click T3 install" becomes a concrete BSH differentiator. ServerAvatar has an API for registering custom applications; this is worth prioritizing alongside the zip path.

**What the installer needs to check**
- PHP version ≥ 8.2
- Required extensions: `json`, `mbstring`, `fileinfo`, `gd` or `imagick`
- `tcms-data/` directory is writable
- `.htaccess` / server rewrite rules are active (test with a known redirect)

---

### Part 2: Updates

**Version check mechanism**

T3 pings a remote endpoint (e.g. `license.totalcms.co/api/version`) on a configurable interval and caches the response. Admin dashboard shows a banner when a newer version is available. The version payload can also carry a changelog excerpt and a severity flag (patch / minor / major) so you can communicate urgency.

**Update process**

1. Admin clicks "Update to X.X.X"
2. T3 downloads the new core zip to a temp directory
3. Verifies checksum
4. Puts site into maintenance mode (serves a clean "updating" page)
5. Swaps `tcms/` directory contents, leaving `tcms-data/` completely untouched
6. Runs any migration scripts (`tcms migrate` equivalent)
7. Clears cache
8. Takes site out of maintenance mode
9. Shows success confirmation with changelog

**CLI equivalent**
```bash
tcms update
tcms update --check   # just report available version, don't apply
```

**Safety considerations**
- Automatic backup of current `tcms/` before swap (kept for one version)
- Rollback command: `tcms update --rollback`
- Update log written to `tcms-data/logs/updates.log`
- Major version updates require explicit confirmation flag: `tcms update --major`

---

**What done looks like**
- A fresh zip install reaches a working admin in under 5 minutes with no terminal
- Composer path reaches a working admin via CLI alone
- Admin dashboard shows correct current version and available update when behind
- Update process completes without touching `tcms-data/`
- Rollback restores previous state cleanly
- `tcms update --check` works from the terminal

