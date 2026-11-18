# WordPress MCP Connector

WordPress plugin for administering a site from OpenAI Codex, Claude Code, or another MCP client. It uses WordPress capabilities and Application Passwords, checks the target environment on every request, and records MCP writes in WordPress admin.

[justDev](https://justdev.org) · [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

## Requirements

- WordPress 6.5+ and PHP 7.4+
- Node.js 20+ for the local Codex and Claude Code bridge
- HTTPS for remote WordPress connections
- ACF 6.1+ for database-backed field group, post type, and taxonomy definitions
- ACF PRO 6.2+ with the Options Pages UI enabled to manage Options Page definitions
- An active Yoast SEO installation for Yoast tools

The WordPress plugin and bridge run from source. There is no build step.

## Installation

### Composer

Run these commands from the WordPress project's Composer root. The package uses `composer/installers` and defaults to `wp-content/plugins/mcp-connector`. Projects with a custom plugins path can override it with Composer's `extra.installer-paths`.

```bash
composer config allow-plugins.composer/installers true
composer require justdev/mcp-connector:dev-develop
```

The `dev-develop` constraint tracks the Git branch `develop`. Until the package is registered on Packagist, add the GitHub repository to your project's Composer configuration before running `composer require`.

```bash
composer config repositories.justdev-mcp-connector vcs https://github.com/justDev-studio/mcp-connector
```

### WordPress setup

1. Activate **WordPress MCP Connector** in **Plugins**. For a manual installation, first copy the `mcp-connector` directory to `wp-content/plugins/`.
2. Set the WordPress environment in `wp-config.php` before WordPress loads. WordPress accepts `local`, `development`, `staging`, and `production`.

   ```php
   define( 'WP_ENVIRONMENT_TYPE', 'staging' );
   ```

3. Create a WordPress Application Password for each client and environment under **Users → Profile**. The password inherits the capabilities of that WordPress user.
4. Copy the endpoint from **Tools → MCP Connector**.

Each MCP request sends `X-WP-MCP-Environment`. WordPress responds with HTTP 409 when the header and environment differ. For a `local` WordPress instance, the bridge also accepts HTTP on a loopback address.

## Client Profiles

Create `~/.config/wp-mcp/profiles.json` outside the project and web root. Set its permissions with `chmod 600 ~/.config/wp-mcp/profiles.json`.

```json
{
    "defaultProfile": "staging",
    "profiles": {
        "staging": {
            "endpoint": "https://staging.example.com/wp-json/mcp-connector/v1/mcp",
            "environment": "staging",
            "username": "agent-staging",
            "applicationPassword": "<staging application password>",
            "mediaDirectory": "/absolute/path/to/media-to-upload"
        },
        "production": {
            "endpoint": "https://example.com/wp-json/mcp-connector/v1/mcp",
            "environment": "production",
            "username": "agent-production",
            "applicationPassword": "<production application password>"
        }
    }
}
```

The bundled plugins use `defaultProfile`. For multiple simultaneous connections, register the bridge under distinct server names and pass a profile name after the script path. Restart the client after editing a profile. `WP_MCP_CONFIG` can point the bridge to a different private profiles file.

### OpenAI Codex

Add the bundled plugin through a [local Codex marketplace](https://developers.openai.com/plugins/build/plugins), or register the bridge directly.

```bash
codex mcp add wordpress-staging -- node /absolute/path/to/mcp-connector/client/bridge.mjs staging
codex mcp add wordpress-production -- node /absolute/path/to/mcp-connector/client/bridge.mjs production
```

### Claude Code

Load the bundled plugin or register a named profile with Claude Code.

```bash
claude --plugin-dir /absolute/path/to/mcp-connector
claude mcp add --transport stdio --scope local wordpress-staging -- node /absolute/path/to/mcp-connector/client/bridge.mjs staging
```

### Remote MCP clients

The endpoint supports stateless Streamable HTTP. Use HTTP Basic authentication with the WordPress username and Application Password, and set `X-WP-MCP-Environment` to the expected WordPress environment. The client must support custom HTTP headers.

## WordPress Tools

| Tool | Use |
| --- | --- |
| `wordpress_site_info` | Inspect the site URL, user, environment, REST collections, and available native admin resources. |
| `wordpress_read` | Read WordPress REST collections and items. Send `method: "OPTIONS"` to inspect the route schema. |
| `wordpress_write` | Create, update, trash, or delete supported WordPress objects. Use the core REST `POST` and `DELETE` methods, including the route's normal arguments. |
| `wordpress_upload_media` | Upload either a `file_path` inside `mediaDirectory` or a `base64` payload with `filename` and `mime_type`. Maximum 8 MiB, subject to the site's smaller limits. |
| `wordpress_update_plugin` | Apply an update found by WordPress. Uses the installed plugin identifier without `.php`, the user’s `update_plugins` capability, and direct filesystem access. |
| `wordpress_activate_theme` | Activate an installed theme available to the site. |
| `wordpress_admin_read` | List or inspect an admin resource. |
| `wordpress_admin_write` | Update an admin resource or move a database-backed ACF definition to Trash. Only the documented resources support `delete`. |

REST support includes posts, pages, media, comments, users, blocks, navigation, menus, widgets, sidebars, block templates, template parts, Global Styles, settings, themes, plugins, and REST-enabled custom post types and taxonomies. The native plugins REST controller installs plugins from WordPress.org and manages activation or deletion. Manage the connector itself from WordPress admin or the site’s deployment workflow.

## ACF

| Resource | Identifier | Update body |
| --- | --- | --- |
| `acf_field_group` | Stable key such as `group_site_footer` | ACF group definition with `title`, `location`, and optionally `fields` |
| `acf_options_page` | Stable ACF Options Page definition key | ACF Options Page definition with `title` and `menu_slug` |
| `acf_post_type` / `acf_taxonomy` | Stable ACF definition key | ACF definition settings |
| `acf_options_page_values` | Registered page’s `menu_slug` | `{ "values": { "field_footer_note": "New note" } }` |

Read a definition before editing it. Definition updates merge top-level properties. If you include `fields`, provide the complete field tree in ACF export format. Keep existing field keys stable to retain their identities. Renaming a field does not migrate values stored under its previous name. Omitted fields are removed from the schema when a new `fields` array is supplied. The connector uses ACF’s importer to handle nested fields and layouts. Definition deletion uses WordPress Trash where recoverable database storage is available.

For example, call `wordpress_admin_write` with the following arguments to create a group assigned to an existing Options Page.

```json
{
    "resource": "acf_field_group",
    "identifier": "group_site_footer",
    "operation": "update",
    "body": {
        "title": "Footer settings",
        "location": [[{ "param": "options_page", "operator": "==", "value": "site-footer" }]],
        "fields": [
            { "key": "field_footer_note", "label": "Footer note", "name": "footer_note", "type": "text" }
        ]
    }
}
```

The options-value resource can list PHP-registered and UI-registered pages. It checks the page’s capability, resolves its storage, and validates only fields assigned to that page. Supply raw ACF field values, such as an attachment ID for an image or field-keyed rows for a repeater. ACF handles the value update and storage format.

PHP-registered schemas and JSON-only definitions are managed in their sources. To edit a database-backed definition that also uses Local JSON, use ACF 6.2 or later, sync the definition, and make sure ACF’s configured save path points to the loaded, writable file. ACF’s standard save hooks handle the JSON export.

## Theme and Plugin Settings

- **Classic themes.** Read `customizer` without an identifier to list settings registered by WordPress, the theme, or plugins. Update one setting with its identifier and `{ "value": ... }`. The connector uses the setting’s capability, validation, and sanitization.
- **Block themes.** Use `global_styles` with identifier `active` to read or update the active theme's customizations. Supply a `styles` or `settings` object, or both. Use `/wp/v2/global-styles/themes/{stylesheet}` to read theme defaults. WordPress exposes `/wp/v2/templates` and `/wp/v2/template-parts` for template content.
- **Custom theme mods and plugin settings.** Use `theme_mod` or `site_option` for explicitly configured keys. The body for an update is `{ "value": ... }`. These resources support `delete` for stored values, subject to WordPress capabilities.
- **Plugin REST routes.** Add exact registered routes for plugin-specific resources that expose REST API controllers. The connector passes GET, OPTIONS, and POST through the route’s own WordPress permission callback.

Configure the extension points in `wp-config.php`.

```php
define( 'WP_MCP_THEME_MODS', array( 'footer_style' ) );
define( 'WP_MCP_SITE_OPTIONS', array( 'my_plugin_settings' ) );
define( 'WP_MCP_EXTRA_REST_ROUTES', array( '/my-plugin/v1/settings' ) );
```

## Yoast SEO

| Resource | Identifier | Update body |
| --- | --- | --- |
| `yoast_settings` | `wpseo`, `wpseo_titles`, or `wpseo_social` | Yoast settings keys returned by the read tool, such as `{ "title-post": "%%title%% %%sep%% %%sitename%%" }` |
| `yoast_post` | WordPress post or page ID as a string | Keys such as `title`, `metadesc`, `focuskw`, and `canonical` |
| `yoast_term` | WordPress term ID as a string | Keys such as `title`, `desc`, and `canonical` |

Yoast’s public REST API presents SEO metadata. To save data, the connector uses Yoast’s WordPress settings and metadata APIs. It checks Yoast’s settings and advanced metadata capabilities and requests a rebuild of the changed post or term’s indexable where supported by Yoast. Read the resource to discover the fields available in the installed version.

## Change History

Open **Tools → MCP Connector** or use **Change history** on the plugin row. The history records the UTC time, environment, WordPress user, Application Password UUID, operation, target, outcome, and available before/after differences for MCP writes. Known sensitive fields and uploaded binary data are omitted. Long values are shortened. `pending` indicates that the operation started but its outcome could not be recorded.

## Scope

The connector covers the REST collections and native resources listed above. Additional application-specific settings can use the extension points in `wp-config.php`. Shell commands, WP-CLI execution, database maintenance, and filesystem-based deployments remain separate operations.
