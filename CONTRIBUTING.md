# Contributing to Mindio Magic MCP

Thank you for improving Mindio Magic MCP. Keep changes focused, preserve compatibility with the published REST namespace, and treat every MCP operation as a security boundary.

## Development setup

Requirements:

- PHP 8.0 or newer
- Composer 2
- A local WordPress 6.4+ installation for integration tests
- Flatsome for UX Builder integration coverage

Run the fast checks before opening a pull request:

```bash
composer validate --strict --no-check-lock
composer lint
composer build
```

Integration tests expect an activated development checkout and a WordPress path:

```bash
WP_PATH=/path/to/wordpress composer test
```

Some suites require their corresponding plugins or fixtures. Run the most relevant suite directly while developing, such as `composer test:oauth` or `composer test:seo-providers`.

## Pull requests

- Open an issue first for large features or compatibility changes.
- Add regression coverage for fixes and new tool behavior.
- Preserve WordPress capability checks, MCP scope checks, strict schemas, redaction, and explicit destructive-action confirmation.
- Update `README.md`, `readme.txt`, and `CHANGELOG.md` when user-facing behavior changes.
- Never commit credentials, production data, vendor dependencies, or files from `dist/`.

By contributing, you agree that your work is licensed under GPL-2.0-or-later.

## Security reports

Do not report vulnerabilities in public issues. Follow [SECURITY.md](SECURITY.md) and use GitHub's private security advisory form.
