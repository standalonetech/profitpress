# Releasing Profitly

This document describes how to package and publish Profitly to the
[WordPress.org plugin directory](https://wordpress.org/plugins/), which
distributes plugins via SVN.

## Key principle: SVN ships a *built* artifact

Git is our source of truth — `vendor/` is git-ignored. WordPress.org SVN is a
**distribution channel**: end users install the plugin as-is, and
`composer install` never runs on their site. Therefore the package committed to
SVN **must include `vendor/`** (the Composer autoloader and any runtime
dependencies), but **must exclude all dev tooling** (PHPUnit, PHPStan, PHPCS).

The `.distignore` file declares what to leave out. `vendor/` is deliberately not
listed there — it is meant to ship.

> As of this scaffold there are **no runtime Composer dependencies** (only
> `php >=7.4`), so a production `vendor/` contains just the autoloader. That is
> expected and sufficient to load the `Profitly\` classes via PSR-4.

## Prerequisites

- SVN access to `https://plugins.svn.wordpress.org/profitly/`
- [WP-CLI](https://wp-cli.org/) with the `dist-archive` command:
  `wp package install wp-cli/dist-archive-command`
- Composer

## 1. Pre-release checks

Run the full quality gate against the source tree (with dev deps installed):

```bash
composer install
composer lint      # phpcs (WordPress-Extra + WordPress-Docs)
composer analyze   # phpstan level 6
composer test      # phpunit
```

Then bump the version in **three** places and keep them identical:

- `profitly.php` header `Version:` and the `PROFITLY_VERSION` constant
- `readme.txt` `Stable tag:`
- `RELEASING.md` / `CHANGELOG` notes as applicable

Update the `== Changelog ==` section of `readme.txt`.

## 2. Build the production package

Install **without** dev dependencies, then build the distributable zip. The
`--no-dev` flag is what keeps PHPUnit/PHPStan/PHPCS out of the shipped
`vendor/`; `.distignore` keeps the remaining dev files out of the archive.

```bash
# Production autoloader + runtime deps only
composer install --no-dev --optimize-autoloader

# Produce profitly.zip honoring .distignore
wp dist-archive . ./profitly.zip
```

Inspect the zip before shipping — confirm `vendor/autoload.php` is present and
that no `tests/`, `phpcs.xml.dist`, or `vendor/bin/` entries leaked in:

```bash
unzip -l profitly.zip | less
```

> After building, restore your dev environment with `composer install` so the
> tooling is available again locally.

## 3. Publish to SVN

WordPress.org SVN has three top-level directories:

- `trunk/` — the latest development version
- `tags/<version>/` — immutable released versions (this is what users download)
- `assets/` — banners, icons, screenshots (NOT shipped to users)

```bash
# One-time checkout
svn checkout https://plugins.svn.wordpress.org/profitly/ profitly-svn
cd profitly-svn

# Sync the built files into trunk (use the unzipped production build, not the
# git working tree). rsync with the same exclusions is a common alternative to
# extracting the zip.
rsync -av --delete \
  --exclude='.svn' \
  /path/to/built/profitly/ trunk/

# Stage adds/removes
svn add --force trunk/* --auto-props --parents -q
svn status | grep '^!' | awk '{print $2}' | xargs -r svn delete

# Tag the release (copy trunk → tags/X.Y.Z)
svn copy trunk tags/0.1.0

svn commit -m "Release 0.1.0"
```

The `Stable tag` in `readme.txt` (in trunk) is what tells WordPress.org which
tag to serve to users — make sure it matches the tag you just created.

## Automating with GitHub Actions (recommended)

The official
[`10up/action-wordpress-plugin-deploy`](https://github.com/10up/action-wordpress-plugin-deploy)
reads `.distignore` and handles the SVN dance on tag push. A minimal workflow
runs `composer install --no-dev --optimize-autoloader` as a build step, then
invokes the action with `SVN_USERNAME` / `SVN_PASSWORD` secrets. This removes
the manual SVN steps above and guarantees the `.distignore` rules are applied
consistently.

## Future note: dependency collision safety

Once Profitly gains real runtime Composer dependencies, consider scoping
their namespaces with [php-scoper](https://github.com/humbug/php-scoper) or
[Strauss](https://github.com/BrianHenryIE/strauss) so a shared library can't
fatally collide with another plugin shipping the same package. Not needed today.
