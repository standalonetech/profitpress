---
description: Update the .pot translation template and build a WordPress.org-ready distribution zip
argument-hint: "(no args)"
allowed-tools: Bash, Read
---

You are packaging the **Profitly** plugin for upload to the WordPress.org plugin
repository. Produce two things: a refreshed `languages/profitly.pot` and a
distributable zip at `dist/profitly.zip` that contains a single top-level
`profitly/` directory.

Use `dist/` (not `build/`) for all packaging output — `build/` is reserved for a
future JS/React build step and must not collide with the release artifact.

Work from the plugin root (`/var/www/html/terawallet/wp-content/plugins/profitly`).
Run the steps in order. **Stop and report** if any step fails — never ship a half-built
zip.

## 1. Version consistency check (warn, don't block)

Read the version from each of these and confirm they match. If any differ, warn the
user clearly and ask whether to continue before building:

- `profitly.php` — the `Version:` header **and** the `PROFITLY_VERSION` constant
- `readme.txt` — `Stable tag:`
- `src/Settings/SettingsRegistry.php` — the `VERSION` constant

```bash
grep -E "Version:|PROFITLY_VERSION" profitly.php
grep -E "Stable tag:" readme.txt
grep -E "const VERSION" src/Settings/SettingsRegistry.php
```

Capture the plugin version (from the `Version:` header) into `$VERSION` for later.

## 2. Build the production autoloader

The shipped zip must include `vendor/` (the autoloader) but **no dev tooling**
(PHPUnit/PHPStan/PHPCS). Install production deps only:

```bash
composer install --no-dev --optimize-autoloader
```

## 3. Update the .pot template

`wp i18n make-pot` reads the plugin header for the package name/version, so this also
fixes a stale `Project-Id-Version`. Exclude non-shipping code so vendor/test strings
never leak into the template:

```bash
wp i18n make-pot . languages/profitly.pot \
  --domain=profitly \
  --exclude=vendor,tests,build,dist,node_modules
```

> `dist` MUST be in the exclude list: it holds the staged copy from a previous run,
> and without excluding it `make-pot` scans that duplicate plugin tree and pollutes
> the template with `dist/profitly/...` file references.

## 4. Stage the files honoring .distignore

Mirror the working tree into `dist/profitly/`, applying the same exclusions
WordPress.org tooling uses. `rsync` reads `.distignore` directly; `/dist` and `/.git`
are already listed there, so the staging dir never copies itself or the repo metadata:

```bash
rm -rf dist/profitly dist/profitly.zip
mkdir -p dist/profitly
rsync -a --delete \
  --exclude-from='.distignore' \
  --exclude='.git/' \
  ./ dist/profitly/
```

## 5. Create the zip

The archive must wrap everything in a top-level `profitly/` folder (WordPress
installs by directory name):

```bash
( cd dist && zip -rq profitly.zip profitly -x '*.DS_Store' )
```

## 6. Verify the artifact (required — do not skip)

Inspect the zip contents and assert the package is correct:

```bash
unzip -l dist/profitly.zip
```

Confirm ALL of the following, and report each as a pass/fail line:

- ✅ `profitly/profitly.php` is present
- ✅ `profitly/vendor/autoload.php` is present
- ✅ `profitly/languages/profitly.pot` is present
- ❌ NONE of these leaked in: `tests/`, `phpunit.xml.dist`, `phpcs.xml.dist`,
  `phpstan.neon.dist`, `composer.json`, `composer.lock`, `.git/`, `.github/`,
  `CLAUDE.md`, `.claude/`, `RELEASING.md`, `README.md` (GitHub-only — WordPress.org
  uses `readme.txt`)
- ❌ no dev packages under `vendor/` (e.g. `vendor/phpunit`, `vendor/phpstan`,
  `vendor/squizlabs`) — their presence means `--no-dev` was not applied

If any assertion fails, say so explicitly and do not present the zip as ready.

## 7. Restore the dev environment

The build left `vendor/` without dev tools. Reinstall them so linting/analysis/tests
work again locally:

```bash
composer install
```

## 8. Report

Tell the user:
- The plugin version that was packaged
- The absolute path to `dist/profitly.zip` and its size
- That the `.pot` was regenerated (and note any new/removed strings if obvious)
- The verification results
- That they can now upload `dist/profitly.zip` to WordPress.org

Do not commit anything — `dist/` and `composer.lock` are gitignored, and the updated
`languages/profitly.pot` is the user's to review and commit.
