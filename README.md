# Mindio Magic MCP

Mindio Magic MCP is a WordPress plugin that exposes a secure, stateless Model Context Protocol server for content, media, site operations, automation, and Flatsome UX Builder page generation.

Mindio Magic MCP is independently developed and is not affiliated with or endorsed by UX Themes. Flatsome is a trademark of its respective owner.

The plugin implements MCP Streamable HTTP over the WordPress REST API, supports API keys and OAuth 2.1 with PKCE, applies WordPress capability checks to every tool call, and keeps a redacted audit trail.

## Requirements

- WordPress 6.4 or newer
- PHP 8.0 or newer
- HTTPS in production
- Flatsome or a Flatsome child theme for page generation and editing; component discovery remains available when the theme is inactive
- Pretty permalinks for root-level OAuth discovery; REST metadata endpoints remain available otherwise

Optional free integrations are detected from installed WordPress plugins: Advanced Custom Fields Free 6.1+, Contact Form 7, WooCommerce, Yoast SEO Free, and Rank Math SEO Free. Their read/write dispatchers are registered only while the dependency is installed. An installed-but-inactive integration remains governable in the admin panel, but execution requires activation. The six legacy WooCommerce tools and two multisite tools are registered only when those features are active. Gravity Forms is a separate commercial plugin and is not bundled or feature-gated behind a Mindio Magic MCP paid tier.

## Installation

1. Upload the release ZIP in **Plugins → Add New → Upload Plugin**, or copy the `mindio-magic-mcp` directory into `wp-content/plugins/`.
2. Activate **Mindio Magic MCP**.
3. Open **Settings → Mindio Magic MCP**.
4. Create an API key for a WordPress user and select the smallest suitable scope.
5. Copy the key immediately. Only its keyed hash is retained.

The MCP endpoint is:

```text
https://example.com/wp-json/flatsome-mcp/v1/mcp
```

The REST namespace intentionally remains `flatsome-mcp/v1` so existing MCP clients, OAuth grants, and integrations continue to work after upgrading to Mindio Magic MCP.

## Administration console

The responsive console under **Settings → Mindio Magic MCP** separates operational work into six focused tabs:

- **Overview** shows endpoint status, connection URLs, environment readiness, onboarding steps, and recent tool health.
- **Tools** governs the per-site MCP surface with searchable domain groups, per-tool switches, group toggles, enable/disable-all actions, and expandable per-operation policy for large plugin integrations. Integration reads start enabled; integration writes start disabled and must be explicitly selected by an administrator.
- **Credentials** creates scoped API keys and governs dynamically registered OAuth clients.
- **Webhooks** creates signed event destinations and documents the delivery security contract.
- **Activity** provides searchable tool audit records and webhook delivery diagnostics.
- **Settings** groups endpoint limits, browser policy, retention, privileged developer capabilities, and uninstall behavior.

The interface uses a compact flat visual system with restrained 2–4px corners, clear borders, and no decorative gradients or elevation. It is keyboard accessible, responsive, RTL-safe, and internationalized for WordPress.org language-pack delivery. A complete Persian catalog is maintained in the development repository for contribution through translate.wordpress.org. JavaScript only enhances copying, filtering, validation, and destructive-action confirmation; every tab and form remains server rendered.

## MCP transport

The server supports MCP protocol versions `2025-11-25`, `2025-06-18`, and `2025-03-26`. It uses one stateless Streamable HTTP endpoint:

- `POST` accepts one JSON-RPC request or notification.
- Successful requests return `application/json`.
- Notifications return HTTP `202`.
- `GET` and `DELETE` return `405`; this server does not maintain sessions or an SSE stream.

Minimal initialization request:

```bash
curl --request POST 'https://example.com/wp-json/flatsome-mcp/v1/mcp' \
  --header 'Authorization: Bearer fmp_REDACTED' \
  --header 'Content-Type: application/json' \
  --header 'Accept: application/json, text/event-stream' \
  --header 'MCP-Protocol-Version: 2025-11-25' \
  --data '{
    "jsonrpc":"2.0",
    "id":1,
    "method":"initialize",
    "params":{
      "protocolVersion":"2025-11-25",
      "capabilities":{},
      "clientInfo":{"name":"my-agent","version":"1.0"}
    }
  }'
```

Use `tools/list` after initialization. Tool availability is filtered by the administrator exposure policy, credential scope, WordPress user capabilities, active plugins, and multisite state.

## Authentication and authorization

Three authentication paths are supported:

- Plugin API keys generated in the admin settings page
- OAuth 2.1 authorization code flow with PKCE S256, dynamic client registration, rotating refresh tokens, and protected-resource metadata
- WordPress-authenticated REST requests, including Application Passwords and compatible external OAuth plugins

OAuth discovery documents:

```text
https://example.com/.well-known/oauth-protected-resource
https://example.com/.well-known/oauth-authorization-server
```

Equivalent REST discovery endpoints are available below `wp-json/flatsome-mcp/v1/oauth/`.

Configure clients with the MCP endpoint (`wp-json/flatsome-mcp/v1/mcp`), not a discovery URL. Discovery is automatic. The authorization endpoint uses a standalone, RTL-safe consent screen with explicit approve/reject actions and a short result handoff before returning to the registered client callback. For client interoperability, this server recognizes only its own exact discovery endpoints as resource aliases and canonicalizes them to the MCP endpoint; issued codes, access tokens, and refresh tokens remain audience-bound to that canonical endpoint.

Scopes are hierarchical:

| Scope | Purpose |
| --- | --- |
| `read_only` | Read and search tools allowed by the associated WordPress user |
| `editor` | Read plus content, media, comments, SEO, Flatsome, and other editorial writes |
| `admin` | Full registered surface, still bounded by WordPress capabilities and safety controls |

A scope never grants permissions that the associated WordPress user does not have.

## Tool catalog

Version 0.5.4 registers 82 core tool names on a single-site installation. Each installed supported integration adds one read and one write dispatcher; installing all five adds ten names and 138 fixed operations. Active WooCommerce adds six compatible legacy names, and multisite adds two. Missing integrations are absent from MCP discovery and the admin policy screen.

Administrators can disable any registered tool under **Settings → Mindio Magic MCP → Tools**. Disabled tools are omitted from `tools/list` and direct calls fail with `tool_disabled`; credentials and their scopes remain unchanged. ACF, Contact Form 7, Yoast, Rank Math, and WooCommerce controls are shown only when the corresponding plugin is installed. Installed-but-inactive integrations remain configurable, while their calls fail closed until activation. Expand a dispatcher to enable individual operations. A disabled operation is removed from the dispatcher's `operation` enum and direct calls fail with `operation_disabled`. Tool and operation policies are stored per site and retained while a dependency is absent.

| Area | Tools |
| --- | --- |
| Content | `create_post`, `get_post`, `update_post`, `delete_post`, `publish_post`, `schedule_post`, `list_posts` |
| Gutenberg | `list_blocks`, `get_block_schema`, `get_post_blocks`, `list_patterns`, `add_block`, `update_block`, `remove_block`, `move_block`, `duplicate_block`, `insert_pattern` |
| Media | `upload_media`, `list_media`, `attach_media`, `delete_media` |
| SEO | `get_meta`, `update_meta`, `yoast_seo_read`, `yoast_seo_write`, `rank_math_read`, `rank_math_write` |
| Site | `get_settings`, `update_settings` |
| Comments | `list_comments`, `approve_comment`, `delete_comment`, `reply_comment` |
| Users | `list_users`, `create_user`, `update_user`, `delete_user`, `send_password_reset` |
| Search | `search_content` |
| Automation | `generate_post_from_prompt`, `summarize_content`, `translate_content`, `bulk_actions` |
| Plugin packages | `list_plugins`, `search_plugins`, `install_plugin`, `update_plugin`, `activate_plugin`, `deactivate_plugin`, `delete_plugin` |
| Theme packages | `list_themes`, `search_themes`, `install_theme`, `update_theme`, `delete_theme`, `switch_theme` |
| Generic and Flatsome themes | `get_theme_context`, `get_theme_mods`, `update_theme_mods`, `create_child_theme`, `get_flatsome_theme_settings`, `update_flatsome_theme_settings` |
| Free plugin integrations | `acf_read`, `acf_write`, `contact_form_7_read`, `contact_form_7_write` |
| Webhooks | `register_webhook`, `unregister_webhook`, `list_webhooks` |
| Flatsome | `list_flatsome_components`, `create_flatsome_page`, `get_flatsome_page`, `add_section`, `add_row`, `add_element` |
| Diagnostics | `get_server_status`, `get_activity_logs`, `get_webhook_logs`, `get_error_logs` |
| Filesystem and database | `read_file`, `list_directory`, `search_files`, `list_database_tables`, `describe_database_table`, `run_safe_query` |
| Developer | `run_wp_cli`, `clear_cache` |
| Performance | `purge_cdn`, `control_cache`, `trigger_image_optimization` |
| WooCommerce | `woocommerce_read`, `woocommerce_write`; legacy `create_product`, `update_product`, `list_orders`, `manage_customers`, `manage_inventory`, `apply_coupons` when active |
| Multisite | `list_sites`, `switch_site_context` |

### Free integration coverage

| Integration | Operations | Coverage |
| --- | ---: | --- |
| ACF Free | 14 | Field groups, native free fields, field values, ACF-managed post types, and taxonomies |
| Contact Form 7 | 7 | Form listing, definitions, create/update/duplicate/delete, and confirmed non-file submission |
| Yoast SEO Free | 6 | Post and taxonomy SEO, robots, canonical/social metadata, schema types, and curated global settings |
| Rank Math SEO Free | 6 | Post and taxonomy SEO, robots, canonical/social metadata, confirmed schema replacement, and curated settings |
| WooCommerce Free | 105 | Products and variations, taxonomy, reviews, orders, refunds, coupons, customers, tax, shipping, payments, settings, webhooks, data, reports, and status tools |

Dispatcher calls use this shape:

```json
{
  "operation": "get_product",
  "arguments": { "product_id": 123 }
}
```

Omit `operation` to inspect enabled operations for an active dependency. The dispatcher validates `arguments` against the selected operation's strict inner schema, then applies its own scope and WordPress capability check.

Every object input schema also accepts:

- `response_locale`: switch response messages to an installed WordPress locale for that call.
- `site_id`: on multisite, execute that one call in a permitted site context. Context is intentionally stateless, so include it on every relevant call.

## Gutenberg editing safety

Gutenberg tools work with structured block trees and numeric index paths. Writes validate every block type against WordPress's live block registry, enforce depth/node limits, save a revision, and accept `expected_modified_gmt` for optimistic concurrency. Posts containing Flatsome or mixed builder content are protected from accidental block serialization; an override requires both `force_non_gutenberg=true` and `confirm=true`. Use the Flatsome tools for those pages whenever possible.

## Flatsome page generation

`create_flatsome_page` accepts a declarative hierarchy of sections, rows, columns, and strict typed components. Version 0.2 is native-first: it emits the corresponding UX Builder shortcode for titles, rich text, images, buttons, banners, feature and image boxes, message boxes, sliders, banner grids, accordions, tabs, galleries, videos, countdowns, testimonials, team members, pricing tables, logos, dividers, gaps, blog posts, products, product categories, social follow/share links, maps, and search.

Use `list_flatsome_components` before generation to discover the active Flatsome version, supported types, shortcode availability, dependencies, and valid child types. WooCommerce components fail clearly when WooCommerce is unavailable. If no supported native component can represent a design, use the explicit `html` type; it is sanitized, wrapped in `ux_html`, and listed in the write result's `render_report`. A mapped component whose shortcode is unavailable receives a safe semantic HTML fallback and the same diagnostic report.

Headings must use `title`. The `text` type accepts `content` containing body-copy markup only—paragraphs, lists, links, emphasis, quotes, and inline text—and renders it inside native `ux_text`. Layout, heading, media, script, iframe, and shortcode markup is rejected. The former `text.html` contract was intentionally removed in 0.2.

Example tool arguments:

```json
{
  "title": "صفحه خدمات",
  "status": "draft",
  "content_locale": "fa_IR",
  "direction": "auto",
  "sections": [
    {
      "label": "Hero",
      "background_color": "#f5f5f5",
      "padding": "72px 0",
      "rows": [
        {
          "horizontal_align": "center",
          "columns": [
            {
              "span": 10,
              "span_mobile": 12,
              "elements": [
                {
                  "type": "title",
                  "text": "خدمات حرفه‌ای برای رشد کسب‌وکار",
                  "tag": "h1",
                  "style": "center"
                },
                {
                  "type": "text",
                  "align": "center",
                  "content": "<p>یک معرفی کوتاه و <strong>روشن</strong>.</p>"
                },
                {
                  "type": "button",
                  "text": "شروع همکاری",
                  "link": "/contact/",
                  "style": "outline"
                }
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

Container components use typed children: accordions and tabs contain titled item arrays, sliders contain supported slide components, banner grids contain structured banners, message boxes contain leaf components, and pricing tables contain structured bullets plus a typed button. Incremental edit tools return and consume stable `_id` values. Pass `expected_modified_gmt` from the prior read/write response to detect concurrent edits instead of overwriting another editor's work.

Every Flatsome write result includes `render_report.native_count`, `fallback_count`, a per-type `components` summary, and a `fallbacks` list with node ID, requested type, and reason. Existing page content is never migrated automatically; newly added fragments use the current native-first contract.

## Automation providers

The plugin does not transmit prompts or content to a third-party AI service by default. `summarize_content` has a local extractive fallback. Generation and translation fail closed until an integration supplies these filters:

```php
add_filter(
    'flatsome_mcp_automation_generate_post',
    function ( $result, array $request, int $user_id ) {
        // Return WP_Error on failure, or an array with title and content.
        return array(
            'title'    => 'Generated title',
            'content'  => '<p>Generated content</p>',
            'excerpt'  => 'Optional excerpt',
            'provider' => 'my-provider',
        );
    },
    10,
    3
);

add_filter(
    'flatsome_mcp_automation_translate_content',
    function ( $result, array $request, int $user_id ) {
        return array(
            'title'    => 'Translated title',
            'content'  => '<p>Translated content</p>',
            'provider' => 'my-provider',
        );
    },
    10,
    3
);
```

Providers may also override local summarization with `flatsome_mcp_automation_summarize_content`.

## Webhooks

Supported events are `post_created`, `post_updated`, `comment_added`, and `order_created`. Delivery is asynchronous through WP-Cron with bounded retries at 60, 300, 900, and 3600 seconds.

Webhook requests contain these primary headers:

```text
X-MagicMCP-Event
X-MagicMCP-Delivery
X-MagicMCP-Timestamp
X-MagicMCP-Signature-256: sha256=<hex digest>
```

The corresponding `X-Flatsome-MCP-*` headers are also sent as deprecated compatibility aliases for existing webhook consumers.

Verify the signature by calculating HMAC-SHA256 over `<timestamp>.<raw request body>` with the signing secret. Reject stale timestamps before processing the payload.

Only public HTTPS destinations are accepted. DNS is checked at registration and again before every delivery, redirects are disabled, and WordPress safe HTTP transport is used.

## Security defaults

- Exact-origin validation for browser requests; native clients may omit `Origin`
- Fixed-window rate limiting per credential
- Strict input schemas with unknown properties rejected
- WordPress capability checks before every callback
- One-time raw credentials with keyed hashes at rest
- Encrypted webhook signing secrets
- Explicit confirmation for destructive or high-impact operations
- Redacted audit inputs and bounded log retention
- Remote media and webhook SSRF defenses
- WordPress.org-only plugin and theme installation with verified official HTTPS package URLs
- `read_file`, `list_directory`, `search_files`, `run_safe_query`, and `run_wp_cli` disabled until explicitly enabled
- In-process WP-CLI allowlist; no shell execution
- Bounded read-only SQL validator with current-site table allowlisting and credential tables denied
- Read-only filesystem roots, traversal and symlink containment, text-file allowlisting, size/time limits, sensitive filename denial, and secret redaction

### WordPress.org directory boundaries

- There are no license checks, paid feature gates, trials, time limits, usage cutoffs, telemetry, advertisements, or remote activation dependencies.
- The plugin has no PHP/JavaScript editor, file manager, arbitrary command runner, or path for storing agent-generated executable code. Agent-supplied HTML and block markup are filtered through WordPress KSES before storage.
- `run_wp_cli` exposes four fixed in-process maintenance commands and never invokes a shell. `create_child_theme` writes only a fixed plugin-owned bootstrap template and accepts no PHP or JavaScript input.
- Plugin and theme packages are downloaded only after an explicit authorized request, must resolve to verified HTTPS URLs on `downloads.wordpress.org`, and are installed through WordPress core upgraders.
- The product is differentiated by native-first Flatsome generation and editing across 29 typed UX Builder components, revision-safe Gutenberg operations, granular tool governance, OAuth 2.1, Persian localization, and RTL support.

See [SECURITY.md](SECURITY.md) for reporting and deployment guidance.

## Localization and RTL

The plugin text domain is `mindio-magic-mcp`. English is the source language, and WordPress.org generates and delivers runtime language packs through translate.wordpress.org. The development repository maintains a complete Persian (`fa_IR`) catalog for contribution and quality checks, but PO and MO files are intentionally excluded from WordPress.org release ZIPs. API callers can request any installed locale with `response_locale`.

Generated Flatsome pages derive direction from `content_locale` when `direction` is `auto`. RTL pages receive scoped `fmp-rtl` classes and a small frontend stylesheet without globally changing the theme.

## Development and release

The integration smoke test expects a WordPress fixture with this plugin activated. Flatsome is required for the rendering assertion.

```bash
WP_PATH=/path/to/wordpress composer test:integration
WP_PATH=/path/to/wordpress composer test:expanded
# Run separately while Yoast Free or Rank Math Free is active:
WP_PATH=/path/to/wordpress composer test:seo-providers
composer lint
composer build
```

The build script creates `dist/mindio-magic-mcp-0.5.4.zip` with the canonical `mindio-magic-mcp` plugin directory and main file. It excludes tests, local metadata, development PO/MO catalogs, and other development files. Existing REST routes, database options, hooks, credentials, and webhook headers remain compatible with earlier releases.

## Extension hooks

The `flatsome_mcp_*` hook names are retained as a stable compatibility API.

- `flatsome_mcp_automation_generate_post`
- `flatsome_mcp_automation_summarize_content`
- `flatsome_mcp_automation_translate_content`
- `flatsome_mcp_media_uploaded`
- `flatsome_mcp_post_created`
- `flatsome_mcp_post_updated`
- `flatsome_mcp_layout_updated`
- `flatsome_mcp_seo_updated`
- `flatsome_mcp_purge_cdn`
- `flatsome_mcp_cache_cleared`
- `flatsome_mcp_optimize_attachment`
- `flatsome_mcp_tool_exception`

## License

GPL-2.0-or-later.
