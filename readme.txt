=== Mindio Magic MCP ===
Contributors: farvisun
Tags: mcp, ai, flatsome, automation, oauth
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.5.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A secure MCP server for WordPress that supports Flatsome UX Builder, content automation, and site management.

== Description ==

Mindio Magic MCP exposes WordPress operations to compatible AI agents through the Model Context Protocol.

Mindio Magic MCP is independently developed and is not affiliated with or endorsed by UX Themes. Flatsome is a trademark of its respective owner.

Highlights:

* Stateless MCP Streamable HTTP endpoint
* API keys, OAuth 2.1 authorization code flow, PKCE S256, and WordPress authentication
* Hierarchical read-only, editor, and administrator scopes
* Structured Gutenberg block discovery and revision-safe block-tree editing
* Post, media, comments, users, SEO, settings, plugin, theme, webhook, search, and diagnostics tools
* Official-directory plugin/theme search, installation, updates, deletion, generic theme settings, and Flatsome settings
* Free integrations for ACF, Contact Form 7, WooCommerce, Yoast SEO, and Rank Math with fixed operation catalogs
* Opt-in, bounded, read-only filesystem and database inspection
* Native-first Flatsome sections, rows, columns, and 29 typed UX Builder components with reported HTML fallback
* Persian content support and RTL-safe generated layouts
* Conditional WooCommerce and multisite tools
* Rate limits, strict schemas, audit logs, SSRF controls, and destructive-action confirmations
* Responsive flat enterprise admin console with compact 2–4px geometry and separate Overview, Tools, Credentials, Webhooks, Activity, and Settings tabs
* Searchable, grouped per-site tool exposure policy with individual, group, and enable/disable-all controls
* Expandable per-operation integration policy; reads start enabled and writes start disabled
* Searchable diagnostics, copy-ready endpoints, accessible controls, and WordPress.org language-pack support

The core single-site plugin registers 82 MCP tool names. Each installed supported integration adds read and write dispatchers; all five integrations add 10 names and 138 fixed operations. Active WooCommerce adds 6 compatible legacy tools and WordPress multisite adds 2 tools.

No prompt or content is sent to an external AI provider by default. Generation and translation integrations are opt-in through documented WordPress filters.

Development source and reproducible release tooling are available at https://github.com/farvisun/mindio-magic-mcp.

== Installation ==

1. Upload the plugin ZIP from Plugins > Add New > Upload Plugin.
2. Activate Mindio Magic MCP.
3. Open Settings > Mindio Magic MCP.
4. Generate an API key for a WordPress user using the least-privileged suitable scope.
5. Configure your MCP client with the displayed REST endpoint.

Use HTTPS in production. Flatsome or a Flatsome child theme must be active for UX Builder tools.

== External services ==

Mindio Magic MCP does not contact an external service merely because it is installed or activated, and it includes no telemetry or tracking.

* WordPress.org directory services: When an authorized administrator or MCP agent explicitly searches for, installs, or updates a plugin or theme, Mindio Magic MCP uses the WordPress.org APIs and official download servers. Search terms, package slugs, and standard HTTP connection metadata are sent to WordPress.org. Service: https://wordpress.org/ — Privacy policy: https://wordpress.org/about/privacy/.
* Administrator-selected media URLs: The upload_media tool can download a file from a URL supplied in that individual request. The selected server receives a normal HTTPS request from the WordPress site. No site content is added to that request.
* Administrator-configured webhooks: After an administrator registers and enables a webhook URL, subscribed WordPress events send a signed JSON payload to that URL. Payloads contain the site name and URL plus event-specific identifiers and metadata. The administrator is responsible for the destination service and its privacy terms.
* HTTPS OAuth Client ID Metadata: When an MCP client identifies itself with an HTTPS Client ID URL, Mindio Magic MCP retrieves that URL to validate the client's redirect metadata before authorization. The client host receives a normal HTTPS request from the WordPress site.
* Optional automation providers: Mindio Magic MCP ships without an AI provider. A site owner may connect one through documented WordPress filters; data handling and terms then depend on that site-specific integration.

== Privacy ==

Mindio Magic MCP stores credentials, OAuth client registrations, per-site policies, webhook configuration, and bounded audit/delivery logs in the WordPress database. Secrets are hashed or encrypted where they must be recoverable. The plugin does not sell data, display public credits, or track usage. Administrators control retention periods and can opt into full plugin-data removal on uninstall.

== Frequently Asked Questions ==

= Does this require Flatsome? =

No for general WordPress tools. Flatsome is required for page generation and editing. list_flatsome_components remains available when the theme is inactive so agents can diagnose compatibility.

= Does the plugin call an AI service? =

No. Content generation and translation fail closed until a site owner connects a provider through the documented filters. Summarization has a local extractive fallback.

= Does it support OAuth? =

Yes. It includes OAuth 2.1 authorization code flow with PKCE S256, dynamic client registration, rotating refresh tokens, MCP protected-resource metadata, and a standalone translated consent/result experience. Enter the MCP endpoint in your client; OAuth discovery URLs are used automatically.

= Are developer tools enabled by default? =

No. Read-only filesystem inspection, read-only SQL, and in-process WP-CLI commands must be enabled separately by an administrator and remain tightly allowlisted.

= Can administrators disable individual MCP tools? =

Yes. Open the Tools tab to control the MCP surface per site. Disabled tools disappear from discovery and direct calls are rejected without changing existing credentials or scopes. ACF, Contact Form 7, Yoast, Rank Math, and WooCommerce dispatchers also expose individual operation switches. Their write operations are disabled by default.

= When are plugin integration controls shown? =

Only when WordPress detects that integration plugin as installed. Installed but inactive plugins remain configurable, although their MCP operations cannot execute until the plugin is activated. Saved policy is retained if a plugin is later removed and restored if it is installed again.

= Does it support Gravity Forms? =

No. Gravity Forms is a separate commercial plugin and is not bundled, required, or feature-gated by Mindio Magic MCP. There is no paid Mindio Magic MCP tier that unlocks it.

= Does Mindio Magic MCP execute arbitrary code? =

No. It provides no PHP or JavaScript editor, file manager, arbitrary command runner, or AI-generated executable-code path. Agent-supplied HTML is filtered through the WordPress KSES allowlist. WP-CLI is limited to four fixed in-process maintenance commands with no shell, and child-theme creation writes only a fixed plugin-owned bootstrap template that accepts no executable-code input.

= Does it download executable code? =

Only when an authorized administrator explicitly requests a plugin or theme install/update. Packages must resolve to verified HTTPS URLs on downloads.wordpress.org and are installed by WordPress core upgraders. Mindio Magic MCP does not fetch executable code from any other service.

= Is any included functionality restricted by payment, a license, or a trial? =

No. The plugin contains no license check, paywall, feature gate, time limit, usage cutoff, or remote activation dependency. Optional integrations appear only when their independently distributed WordPress plugins are installed.

= How is this different from other MCP plugins? =

Its primary distinction is native-first Flatsome UX Builder generation and editing across 29 typed components, combined with revision-safe Gutenberg operations, granular per-tool and per-operation policy, OAuth 2.1, Persian localization, and RTL-safe generated layouts.

== Changelog ==

= 0.5.4 =

* Updated public repository metadata and release documentation for the canonical Mindio Magic MCP repository.
* Switched to WordPress.org language-pack delivery and removed hardcoded assumptions about the uploads directory.

= 0.5.3 =

* Standardized the WordPress.org slug, text domain, plugin directory, main release file, and development translation catalogs as mindio-magic-mcp.
* Preserved existing REST routes, database options, hooks, credentials, and webhook headers for upgrade compatibility.

= 0.5.2 =

* Always filter agent-supplied post, automation, WooCommerce, Gutenberg, and ACF markup through WordPress sanitization before storage.
* Added field-aware ACF value validation and regression tests for executable markup removal.
* Clarified WordPress.org directory compliance boundaries, external package sources, free availability, and product differentiation.

= 0.5.1 =

* Rebranded the public plugin, admin console, OAuth experience, and MCP display identity as Mindio Magic MCP.
* Preserved legacy REST routes, database options, hooks, credentials, and webhook headers for upgrades.

For the complete release history, see https://github.com/farvisun/mindio-magic-mcp/blob/main/CHANGELOG.md.
