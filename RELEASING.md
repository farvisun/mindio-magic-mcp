# Releasing Mindio Magic MCP

This is the complete runbook for shipping a version to GitHub and to the WordPress.org plugin
directory. Follow it top to bottom; every step assumes the previous one succeeded.

The plugin is published on WordPress.org under the slug `mindio-magic-mcp`, which is also the
canonical directory name, main file name, text domain, and REST namespace prefix. Nothing in a
release may reintroduce the pre-0.6.0 `flatsome-mcp` identity.

## Prerequisites

| Tool | Used for | Install |
| --- | --- | --- |
| PHP 8.0+ | linting, tests, build | `brew install php` |
| Composer | script runner | `brew install composer` |
| `rsync`, `zip`, `unzip` | release archive | bundled with macOS |
| Subversion | WordPress.org deployment | `brew install subversion` |
| GNU gettext | catalog merge (`msgmerge`, `msgfmt`, `msgattrib`) | `brew install gettext` |
| WP-CLI | regenerating the POT catalog | https://wp-cli.org/ |
| `gh` | inspecting GitHub releases | `brew install gh` |

You also need a local WordPress install to run the integration suite against, and a
WordPress.org account with commit access to the plugin.

## Version surfaces that must agree

A release is only consistent when every one of these carries the same version string. The CI
release workflow hard-fails if the first two disagree, and WordPress.org serves whatever
`Stable tag` points at, so a mismatch there ships the wrong code to every site.

| File | Line |
| --- | --- |
| `mindio-magic-mcp.php` | `* Version:` plugin header |
| `mindio-magic-mcp.php` | `define( 'MINDIO_MAGIC_MCP_VERSION', ... )` |
| `readme.txt` | `Stable tag:` |
| `languages/mindio-magic-mcp.pot` | `Project-Id-Version:` |
| `languages/mindio-magic-mcp-fa_IR.po` | `Project-Id-Version:` |
| SVN `tags/<version>/` | directory name created during deployment |
| Git tag | `v<version>` |

Note the git tag carries a `v` prefix and the SVN tag never does.

Bump `MINDIO_MAGIC_MCP_DB_VERSION` separately, and only when the release adds or alters a
database table. It is independent of the plugin version.

## 1. Prepare the code

1. Work on `main` with a clean tree. `git status` must report nothing.
2. Move the `## Unreleased` heading in `CHANGELOG.md` to `## <version> - <YYYY-MM-DD>`.
3. Bump every version surface in the table above.
4. Add a `= <version> =` block to the `== Changelog ==` section of `readme.txt`. Keep roughly
   the three most recent releases inline and let the link to `CHANGELOG.md` cover the rest.
5. Add a matching `= <version> =` block to `== Upgrade Notice ==`. WordPress renders this on the
   update screen, so keep it under 300 characters and say what an existing site should expect.
6. Refresh any claim in `readme.txt` and `README.md` that the release invalidates — the core
   tool count, the admin tab list, the example archive filename, the SVN commit message.

To recount the core tool names, call `tools/list` against a live install with an administrator
credential and subtract the integration dispatchers, the WooCommerce compatibility tools, and
the multisite tools, since those depend on what is installed.

## 2. Refresh the translation catalogs

New user-facing strings must land in the catalogs before the archive is built, otherwise the
localization suite fails and translate.wordpress.org has nothing to offer translators.

```bash
wp i18n make-pot . languages/mindio-magic-mcp.pot \
  --slug=mindio-magic-mcp \
  --exclude=tests,bin,docs,dist,.wordpress-org

# make-pot rewrites the header from the plugin name, so restore the version by hand:
# "Project-Id-Version: Mindio Magic MCP <version>\n"

msgmerge --update --backup=none --no-fuzzy-matching \
  languages/mindio-magic-mcp-fa_IR.po languages/mindio-magic-mcp.pot
msgattrib --no-obsolete -o \
  languages/mindio-magic-mcp-fa_IR.po languages/mindio-magic-mcp-fa_IR.po
```

`--no-fuzzy-matching` matters: the localization suite rejects fuzzy entries, so new strings must
arrive empty rather than guessed. `msgattrib --no-obsolete` drops the `#~` entries msgmerge
leaves behind for strings that no longer exist in the source.

Then fill the new Persian entries and compile:

```bash
php bin/sync-fa-translations.php            # lists what is still untranslated
# add each reported msgid to the map in bin/sync-fa-translations.php
php bin/sync-fa-translations.php --write    # exits 0 only when nothing remains
msgfmt --check --statistics -o languages/mindio-magic-mcp-fa_IR.mo languages/mindio-magic-mcp-fa_IR.po
```

If `wp i18n make-pot` warns that a string has two different translator comments, unify the
comments at both call sites. Two comments on one msgid is ambiguous for translators.

The `.po` and `.mo` files are excluded from the release archive by `.distignore`; only the `.pot`
ships. Persian reaches users through WordPress.org language packs built from translate.wordpress.org.

## 3. Verify

```bash
composer validate --strict --no-check-lock
composer lint
WP_PATH=/path/to/wordpress composer test
```

`composer test` runs the linter followed by every integration suite. `test:seo-providers` needs
Yoast Free or Rank Math Free active in the target install; run it separately if your default
fixture has neither.

## 4. Build and inspect the archive

```bash
composer build
unzip -t dist/mindio-magic-mcp-<version>.zip
unzip -l dist/mindio-magic-mcp-<version>.zip | grep -E 'tests/|bin/|\.po$|\.mo$'   # must be empty
unzip -p dist/mindio-magic-mcp-<version>.zip mindio-magic-mcp/readme.txt | head -8
```

The archive must contain exactly one top-level directory named `mindio-magic-mcp`, and the
`Stable tag` printed from inside it must match the version you are releasing.

## 5. Commit, tag, push

Commit messages are short and do not credit tooling.

```bash
git add -A
git commit -m "release: 0.7.0"
git tag v<version>
git push origin main
git push origin v<version>
```

Pushing to `main` triggers `.github/workflows/release.yml`, which re-verifies that the plugin
version and `Stable tag` agree, lints, builds, and publishes a GitHub release with the ZIP and
its SHA-256. The workflow skips itself when a release for that tag already exists, so pushing
`main` again is safe.

Confirm it landed before continuing:

```bash
gh release view v<version>
```

**Do not run the workflow against a stale commit.** If CI is already building the same version
from an earlier commit, cancel that run first — otherwise it publishes an archive whose contents
do not match the tag.

## 6. Deploy to WordPress.org

WordPress.org distributes from Subversion, not from git. The SVN repository has three top-level
directories with distinct meanings:

- `trunk/` — the working copy of the plugin. WordPress.org reads plugin metadata from here.
- `tags/<version>/` — an immutable copy per release. `Stable tag` in `trunk/readme.txt` selects
  which one users actually download.
- `assets/` — directory artwork: icons, banners, screenshots. Never inside the plugin ZIP.

Check out the repository once and keep it outside the git working tree:

```bash
svn checkout https://plugins.svn.wordpress.org/mindio-magic-mcp/ \
  ../wordpress-org-mindio-magic-mcp
```

A full checkout includes every historical tag and is large. `svn checkout --depth immediates`
followed by `svn update --set-depth infinity trunk assets` fetches only what a release needs.

Then stage the release:

```bash
bin/prepare-wordpress-org.sh ../wordpress-org-mindio-magic-mcp
```

The script refuses to touch a working copy that has uncommitted changes or an existing tag for
the current version. It builds the archive, synchronizes the extracted plugin into `trunk/`,
synchronizes `.wordpress-org/` into `assets/`, sets `svn:mime-type` on the PNGs, schedules
deletions for files that disappeared, and copies `trunk/` to `tags/<version>/`.

Review before committing. This is the last reversible moment:

```bash
cd ../wordpress-org-mindio-magic-mcp
svn status
svn diff --summarize
```

Expect additions under `tags/<version>/`, modifications under `trunk/`, and nothing unexpected
under `assets/`. Then commit — one commit for both the trunk update and the new tag:

```bash
svn commit -m "Release <version>" --username <wordpress.org-username>
```

The directory usually reflects the new version within about fifteen minutes.

## 7. Post-release checks

- https://wordpress.org/plugins/mindio-magic-mcp/ shows the new version and changelog.
- The download link serves the new ZIP, and the "Tested up to" value renders as expected.
- Update the plugin on a real site and confirm activation runs the schema upgrade cleanly.
- https://translate.wordpress.org/projects/wp-plugins/mindio-magic-mcp/ picks up the new strings.
- Open a fresh `## Unreleased` heading in `CHANGELOG.md` for the next cycle.

## Things that must never change in a release

Three HMAC context strings and two prefixes are frozen at their pre-rename values because
changing them destroys existing data. They are marked with explanatory comments in the source.
Never "clean these up" as part of a release:

- `Auth::token_hash()` uses `|flatsome-mcp-token`. Changing it invalidates every issued access token.
- `Secret_Box` uses `|flatsome-mcp-secret-box`. Changing it makes every stored secret undecryptable.
- API keys carry an `fmp_` prefix, which is part of every key already in the field.
- Generated page markup carries `fmp-` CSS class names, which live in published post content.
- `MINDIO_MAGIC_MCP_LEGACY_REST_NAMESPACE` stays registered so pre-0.6.0 clients and OAuth grants
  keep working.

## WordPress.org review expectations

Keep these true on every release, since a guideline failure can pull the plugin from the directory:

- GPL-compatible license declared in both the plugin header and `readme.txt`.
- No obfuscated or minified code without its source, and no code fetched from a remote server.
- Every external service the plugin can contact is disclosed under `== External services ==`
  with its privacy policy. Adding a new outbound call means adding an entry there.
- Nothing phones home on activation, and no telemetry runs without opt-in.
- All output escaped, all input sanitized, all queries prepared, and nonce plus capability
  checks on every admin action.
- Everything user-facing is translatable through the `mindio-magic-mcp` text domain, which must
  equal the plugin slug.
- No trademark misuse. The plugin is not affiliated with UX Themes, and `readme.txt` says so.
- `Tested up to` names a real, current WordPress version. Confirm it before each release rather
  than carrying the previous value forward.
