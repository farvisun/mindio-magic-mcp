# Security policy

## Supported versions

Security fixes are provided for the latest tagged minor release.

| Version | Supported |
| --- | --- |
| 0.5.x | Yes |
| Older | No |

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability. Use the repository's private GitHub Security Advisory form:

https://github.com/farvisun/mindio-magic-mcp/security/advisories/new

Include the affected version, prerequisites, impact, reproducible steps, and a minimal proof of concept. Do not include live API keys, OAuth tokens, webhook secrets, customer data, or production database content.

You should receive an acknowledgement within five business days. Publication timing will be coordinated after a fix and supported upgrade path are available.

## Deployment guidance

- Serve WordPress and the MCP endpoint over HTTPS.
- Create a dedicated WordPress user for each integration where practical.
- Grant the smallest MCP scope and WordPress role that can complete the task.
- Rotate and revoke credentials when clients or team membership change.
- Keep WordPress, PHP, Flatsome, WooCommerce, and this plugin updated.
- Leave SQL and WP-CLI tools disabled unless they are explicitly required.
- Restrict browser origins to exact trusted origins; do not add wildcard origins.
- Treat webhook payloads as untrusted input even after verifying their signatures.
- Protect WordPress salts and database backups; token hashes and encrypted webhook secrets depend on site key material.
- Monitor the MCP audit log and webhook delivery log.

## Security boundaries

MCP scopes constrain a credential but never elevate the underlying WordPress user. Each tool also performs a WordPress capability check. Destructive actions use explicit confirmation fields, but a confirmed call remains authoritative—clients should show users the exact target set before invoking one.

Mindio Magic MCP does not claim to secure a compromised WordPress administrator account, server, database, or another plugin executing arbitrary PHP. Site owners remain responsible for plugin/theme trust, host hardening, backups, and incident response.
