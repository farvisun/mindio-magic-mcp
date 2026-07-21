# Changelog

All notable changes to Mindio Magic MCP are documented here.

## 0.5.4 - 2026-07-21

- Updated plugin, readme, security, and development-catalog links to the canonical public repository at `farvisun/mindio-magic-mcp`.
- Switched production translation delivery to WordPress.org language packs, removed the manual text-domain loader, and excluded development PO/MO catalogs from release archives.
- Resolved the uploads root exclusively through the WordPress uploads API without assuming the default `wp-content/uploads` location.

## 0.5.3 - 2026-07-20

- Standardized the WordPress.org slug, gettext text domain, packaged plugin directory, and packaged main file as `mindio-magic-mcp`.
- Renamed the development POT, PO, and MO translation catalogs to match the canonical text domain.
- Preserved the existing REST namespace, database options, hooks, credentials, webhook headers, admin route, and MCP protocol identifier for seamless upgrades.

## 0.5.2 - 2026-07-20

- Always filter agent-supplied post, automation, WooCommerce, Gutenberg, and ACF markup through WordPress sanitization before storage, even when the associated user can normally publish unfiltered HTML.
- Resolve ACF fields before value writes and apply field-aware scalar, URL, identifier, structured-value, and rich-text sanitization.
- Added integration regression coverage proving that executable markup and event attributes are removed from post content, Gutenberg blocks, ACF values, and WooCommerce product descriptions.
- Documented WordPress.org directory boundaries for arbitrary code, official package downloads, free availability, external services, and meaningful Flatsome-focused differentiation.

## 0.5.1 - 2026-07-20

- Rebranded the public plugin, admin console, OAuth experience, and MCP display identity as Mindio Magic MCP.
- Preserved the `magicmcp` plugin directory and text domain, legacy REST routes, database options, hooks, credentials, and webhook headers for seamless upgrades.
- Renamed the downloadable release archive to `mindio-magic-mcp-0.5.1.zip` while retaining the existing inner plugin directory.

## 0.5.0 - 2026-07-20

- Renamed the public plugin and MCP server identity to MagicMCP and identified Mohammad Askari as the author.
- Changed the WordPress.org package slug, main release filename, and translation domain to `magicmcp`.
- Added WordPress.org-compliant metadata plus external-service, privacy, and compatibility documentation.
- Preserved legacy REST routes, database options, hooks, credentials, and webhook headers so existing installations and clients continue to work.
- Updated release tooling to produce an installable `magicmcp` package and added a collision guard for manual upgrades from the legacy package directory.

## 0.4.1 - 2026-07-20

- Register ACF, Contact Form 7, Yoast SEO, Rank Math, and WooCommerce dispatcher tools only when WordPress detects the corresponding plugin as installed, so missing integrations no longer appear in MCP discovery or the administrator policy screen.
- Keep installed-but-inactive integrations available for administrator enable/disable policy while retaining the existing activation requirement at execution time.
- Detect standard, renamed, and must-use plugin installations through official entry files and plugin text-domain headers, and preserve saved policies while dependencies are absent.
- Added server-rendered regression coverage proving that installed integrations expose policy controls and missing integrations do not.

## 0.4.0 - 2026-07-20

- Added ten structured Gutenberg tools for block discovery, schemas, patterns, tree reads, insertion, replacement, movement, duplication, and removal, with block-registry validation, revisions, optimistic concurrency, resource limits, and explicit protection for Flatsome/mixed content.
- Expanded plugin and theme lifecycle coverage with WordPress.org search, verified official package installation, updates, guarded deletion, generic theme context/modifications, child-theme creation, and curated typed Flatsome theme settings.
- Added compact operation dispatchers for ACF Free (14 operations), Contact Form 7 (7), Yoast SEO Free (6), Rank Math SEO Free (6), and WooCommerce Free (105). Dispatcher names remain stable when dependencies are unavailable; read operations default to exposed and writes default to disabled.
- Added administrator operation-level governance with expandable integration rows, operation search, read/write/destructive/scope badges, per-integration read/write bulk actions, live state summaries, RTL behavior, and server-side enforcement in discovery and direct execution.
- Added opt-in read-only filesystem inspection with fixed WordPress content roots, traversal/symlink containment, text and sensitive-file allowlists, secret redaction, and bounded file, directory, search, and runtime limits.
- Added prefix-redacted database inventory and schema tools, plus a hardened current-site SQL allowlist that excludes credential, options, customer, order, session, webhook, and plugin audit data.
- Fixed generic SEO validation ordering, provider schema-update atomicity, ACF default-position handling, and WordPress.org theme-author normalization.
- Added end-to-end coverage for operation policy, Gutenberg, ACF, Contact Form 7, WooCommerce, filesystem/database protections, and both free SEO providers; refreshed full English and Persian catalogs and release documentation.

## 0.3.0 - 2026-07-20

- Added administrator-managed, per-site exposure policy for every registered MCP tool while preserving the current all-exposed default and automatically exposing newly registered tools.
- Added a dedicated searchable Tools tab with domain grouping, live exposed/disabled states, per-tool switches, group toggles, enable/disable-all actions, policy metrics, responsive behavior, and RTL support.
- Disabled tools are now removed from `tools/list`, and direct execution is rejected with a localized `tool_disabled` error independently of credentials and permission scopes.
- Preserved disabled state for temporarily unavailable conditional integrations and remove the dedicated policy option during opted-in uninstall cleanup.
- Added complete Persian translations plus integration coverage for server enforcement and the administrator interface.

## 0.2.0 - 2026-07-19

- Replaced the four-type HTML-heavy Flatsome element contract with strict native-first schemas for 29 UX Builder component types.
- Added typed nested accordions, tabs, sliders, banner grids, message boxes, and pricing tables; dedicated native components now handle headings, cards, media, business content, and dynamic queries.
- Added `list_flatsome_components` with runtime Flatsome version, shortcode, dependency, and child-capability discovery, increasing the base tool count to 55.
- Added per-write native/fallback render reports, safe `ux_html` fallback, semantic degradation for unavailable shortcodes, and clear failures for missing content dependencies.
- Removed legacy `text.html`; `title` now owns headings while `text.content` permits body-copy markup only and rejects layout, media, heading, script, iframe, and shortcode markup.
- Added recursive component/node limits, nested media validation, a 26-component native rendering matrix, fallback security tests, and updated English/Persian documentation.

## 0.1.0 - 2026-07-19

- Added stateless MCP Streamable HTTP transport compatible with MCP 2025-11-25.
- Added scoped API keys, OAuth 2.1 authorization code flow, PKCE S256, discovery metadata, dynamic client registration, and rotating refresh tokens.
- Added a standalone OAuth consent, approval/denial result, and error experience; advertised canonical protected resources; and safely canonicalized this plugin's exact same-origin discovery aliases for MCP client interoperability.
- Added 54 base WordPress, content, media, SEO, automation, webhook, Flatsome, administration, diagnostics, and performance tools.
- Added conditional WooCommerce and multisite tool groups.
- Added declarative Flatsome UX Builder rendering with stable node IDs, revision tracking, conflict detection, Persian content support, and RTL-safe output.
- Added signed asynchronous webhooks with bounded retries and encrypted signing secrets.
- Added strict schema validation, WordPress capability enforcement, per-credential rate limiting, SSRF controls, audit logging, and safe destructive-action confirmations.
- Added a responsive, flat enterprise administration console with compact 2–4px geometry; separate Overview, Credentials, Webhooks, Activity, and Settings tabs; searchable audit activity; webhook delivery health; one-click endpoint copying; and accessible controls.
- Completed the bundled Persian catalog across the entire plugin, including every admin section, MCP tool description, validation message, OAuth flow, Flatsome operation, WooCommerce action, and multisite capability.
- Added network-aware lifecycle handling, localization templates, admin UI regression coverage, integration smoke tests, and release packaging.
