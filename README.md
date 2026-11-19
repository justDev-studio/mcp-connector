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

3. In **Tools → MCP Connector**, use **Development project setup** on a development site. In other environments, choose a WordPress user from the list and select **Create MCP Application Password**. Paste the displayed entry inside the `profiles` object in your private local configuration. If the entry already exists, update its connection fields. The list defaults to the signed-in user when available.
4. For remote staging or production, use the HTTPS endpoint from the profile and make sure it is reachable from the machine running the agent. The password inherits the WordPress user's permissions and works only on the MCP endpoint, while the connector is active.

Each MCP request sends `X-WP-MCP-Environment`. WordPress responds with HTTP 409 when the header and environment differ. HTTP is accepted for `local` only on loopback. The MCP request body limit is 12 MiB, subject to smaller limits set by PHP or the web server.

### Portable passwords for database migrations

MCP Application Passwords are portable by default. Install a connector version that supports portable passwords on all participating sites. Existing passwords issued by the connector also work across environments after updating. Use the same WordPress username and Application Password in each local profile, with separate MCP endpoints and environment values. The connector still checks that each request targets the environment in its profile.

Issue the portable password on the source database before the next copy. The migration must copy the WordPress user and their Application Password metadata. Each site can retain its own WordPress authentication keys and salts. A database backup from before issuance lacks the password record. To revoke the password across all sites, revoke it in each database or propagate the revocation through the next database copy.

## Development Project Setup

With `WP_ENVIRONMENT_TYPE` set to `development`, open **Tools → MCP Connector → Development project setup**. Choose **Set up Codex** for `.codex/config.toml` or **Set up Claude Code** for `.mcp.json`. Each button checks its own file and ignore rules.

Either setup button prepares `.wp-mcp/`, adds its wildcard `.gitignore` and an Apache deny rule if missing, and ignores both client configuration files at the project root. Project setup runs only when a button is pressed.

Each button adds the server to the selected client's file. Both clients share a private profile in `.wp-mcp/`. Setup reuses an existing development profile for this site or issues a WordPress Application Password for the administrator running setup. Revoke passwords issued by MCP Connector before deactivating it. Passwords created directly in the standard WordPress user profile or by earlier versions without environment scoping retain their existing WordPress scope.

Development setup writes `.wp-mcp/profiles.json` with private file permissions when the directory is outside the web root or a development site at its document root passes an HTTP protection check. The check requests a temporary file containing no credentials over HTTPS and HTTP. It accepts HTTPS denial and either HTTP denial or a redirect to the verified HTTPS URL. When setup cannot verify protection, it writes `.wp-mcp/profiles.php` with a PHP guard. Existing valid profiles are reused.

For custom web-server layouts, verify that the server blocks HTTP access before setting `WP_MCP_JSON_PROFILE_WEB_PROTECTED` to `true` in `wp-config.php` or `1` in the environment. The project setup uses this setting as an explicit confirmation of protection.

The root `.gitignore` rules cover `.codex/config.toml`, `.mcp.json`, and `.wp-mcp/`. Existing Git-tracked files remain tracked. Setup preserves existing server entries with the same name.

WordPress needs write access to the shared project checkout. Setup looks for a Git or DDEV root above WordPress and can use the WordPress directory for a standard installation. If needed, set `WP_MCP_PROJECT_ROOT` in `wp-config.php` to the project path as seen by WordPress. Client configurations use relative paths, including when WordPress runs in DDEV.

Open the project root in Codex or Claude Code with Node.js available. Trust the project in Codex and approve the server if prompted by Claude Code. For a DDEV certificate, use a Node version that supports `NODE_USE_SYSTEM_CA` or supply the development CA through `NODE_EXTRA_CA_CERTS`. In a new chat, call `wordpress_site_info` to verify the environment.

## Codex Project Configuration

Add the WordPress MCP server to `.codex/config.toml` in the root of a trusted Codex project. Codex can connect to WordPress directly over Streamable HTTP.

```toml
[mcp_servers.wordpress-staging]
url = "https://staging.example.com/wp-json/mcp-connector/v1/mcp"
http_headers = { "X-WP-MCP-Environment" = "staging" }
env_http_headers = { Authorization = "WP_MCP_STAGING_AUTHORIZATION" }
```

Use the endpoint shown under **Tools → MCP Connector**. Set `WP_MCP_STAGING_AUTHORIZATION` in the environment of the Codex process to `Basic <base64 of username:application-password>`. The encoded value is a credential. Keep it out of `.codex/config.toml` and version control. Add a separate server table and environment variable for each WordPress environment. In a new Codex chat, call `wordpress_site_info` to verify the site and environment.

Direct HTTP uses `base64` uploads. For `file_path` uploads, use the Node.js bridge. The bridge can also be scoped to this project with `.codex/config.toml`.

```toml
[mcp_servers.wordpress-staging]
command = "node"
args = ["/absolute/path/to/mcp-connector/client/bridge.mjs", "staging"]
env = { WP_MCP_CONFIG = "/absolute/path/to/project/.codex/wp-mcp/profiles.json" }
```

Use one transport per server name. Keep a project-local profiles file outside the public web root, ignore it in Git, and set its permissions to `600`.

## Bridge Client Profiles

For the Node.js bridge, create a private profiles file and set its permissions to `600`. Use `WP_MCP_CONFIG` to select a project-specific file. The bridge defaults to `~/.config/wp-mcp/profiles.json` when that variable is unset.

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
            "applicationPassword": "<production application password>",
            "disabled": true
        }
    }
}
```

The optional boolean `disabled` defaults to `false`. With `disabled: true`, the bridge exits before connecting if that profile is selected.

The bundled plugins use `defaultProfile`. For multiple simultaneous connections, register the bridge under distinct server names and pass a profile name after the script path. Restart the client after editing a profile. `WP_MCP_CONFIG` can point the bridge to a different private profiles file.

### Codex Marketplace and CLI

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

### Role access

WordPress administrators and multisite super admins can use the full MCP tool set, subject to WordPress and plugin capabilities. Other users can manage posts, pages, and menus within their existing permissions. The same scope covers custom post types and taxonomies that are visible in WordPress admin and use WordPress post or term REST controllers. Users can edit custom field values on those objects and on ACF Options Pages they can already access. Object-level duplication, translation links, and SEO metadata follow the permissions of the relevant content.

Site and plugin settings, ACF definitions, themes, plugins, Gravity Forms, media library management, and additional REST routes require an administrator in MCP. The MCP Connector settings and change history in WordPress admin also require the administrator role.

| Tool | Use |
| --- | --- |
| `wordpress_site_info` | Inspect the site URL, user, environment, REST collections, and available native admin resources. |
| `wordpress_read` | Read WordPress REST collections and items. Send `method: "OPTIONS"` to inspect the route schema. |
| `wordpress_write` | Create, update, trash, or delete supported WordPress objects. Use `POST` and `DELETE` for WordPress routes and `PUT` to update a Gravity Forms form. |
| `wordpress_upload_media` | Upload either a `file_path` inside `mediaDirectory` or a `base64` payload with `filename` and `mime_type`. Maximum 8 MiB, subject to the site's smaller limits. |
| `wordpress_update_plugin` | Apply an update found by WordPress. Uses the installed plugin identifier without `.php`, the user’s `update_plugins` capability, and direct filesystem access. |
| `wordpress_activate_theme` | Activate an installed theme available to the site. |
| `wordpress_admin_read` | List or inspect an admin resource. |
| `wordpress_admin_write` | Update an admin resource or move a database-backed ACF definition to Trash. Only the documented resources support `delete`. |

REST support includes posts, pages, media, comments, users, blocks, navigation, menus, widgets, sidebars, block templates, template parts, Global Styles, settings, themes, plugins, and REST-enabled custom post types and taxonomies. The native plugins REST controller installs plugins from WordPress.org and manages activation or deletion. Manage the connector itself from WordPress admin or the site’s deployment workflow.

## WordPress Site Settings

Use `wordpress_read` with `/wp/v2/settings` to read site-wide settings and `wordpress_write` with `POST` to update them. Use `OPTIONS` to discover the site's schema. The core endpoint covers settings such as the site title, tagline, timezone, date and time formats, front page, posts per page, and comment defaults.

Use `wordpress_admin_read` or `wordpress_admin_write` with resource `wordpress_settings` and identifier `site` for `permalink_structure`, `category_base`, `tag_base`, and `blog_public`. Set `blog_public` to `false` to discourage search engines from indexing the site. Permalink updates refresh WordPress rewrite rules in the database.

## Custom Post Records

Use `wordpress_site_info` to find a post type's REST collection. WordPress exposes custom types that are registered with `show_in_rest`, including their configured REST namespace and base. For example, `wordpress_read` can list records at `/wp/v2/books` or fetch `/wp/v2/books/42`. Send `wordpress_write` with `POST` to the item route to update it. Fields registered in the REST schema can be updated in the same request.

## Gravity Forms

Gravity Forms routes appear when the plugin's REST API v2 is enabled in **Forms → Settings → REST API**. The connector uses the authenticated WordPress user's Gravity Forms capabilities.

| Operation | Tool and route |
| --- | --- |
| List or read forms | `wordpress_read` with `/gf/v2/forms` or `/gf/v2/forms/{id}` |
| Create a form | `wordpress_write` with `POST` to `/gf/v2/forms` and a Gravity Forms Form Object |
| Update form settings, fields, notifications, or confirmations | `wordpress_write` with `PUT` to `/gf/v2/forms/{id}` |
| List and filter submissions | `wordpress_read` with `/gf/v2/entries` or `/gf/v2/forms/{id}/entries` |
| Read one submission | `wordpress_read` with `/gf/v2/entries/{id}` |

Form updates merge the supplied top-level properties into the existing form. A supplied `fields`, `notifications`, or `confirmations` property replaces that section, so read the current form first and keep the existing entries and IDs you want to preserve. For submission lists, use `query.paging`, `query.sorting`, and `query.search`. For example, `{"paging":{"page_size":20,"current_page":1},"search":{"status":"active"}}` retrieves the first page of active submissions. Use `_field_ids` and `_labels` to select which fields to return.

## Plugin integrations

Use `wordpress_site_info` to discover the resources available on the current site. Read the resource with `wordpress_admin_read` before updating it with `wordpress_admin_write`. Plugin-specific capabilities still apply. The MCP audit log records changes and omits credential values.

| Plugin | MCP resources and identifiers |
| --- | --- |
| WPML | `wpml_languages` (site) reads and sets active and default languages. `wpml_content` (`post:42` or `term:15`) reads translation groups and assigns or links existing objects with language and `translation_of`. Set language on `wordpress_read` to select a language for REST reads. |
| WPML String Translation | `wpml_strings` reads and updates a registered string by numeric ID. Use `query.context`, `query.name`, and `query.source` to find its ID. Updates accept language and value. |
| Complianz | `complianz` (site) reads the available settings and wizard fields and saves their values through the plugin's sanitizers and save hooks. `complianz_banner` lists and updates settings on existing banners. |
| Media Cloud | `media_cloud` (site) manages the supported S3 and offload settings, including media types, URL rewriting, and write-only credentials. |
| Yoast Duplicate Post | `duplicate_post_settings` (site) manages copying options. `duplicate_post` accepts a numeric post ID and the action `new_draft` to create a copy. |
| EWWW Image Optimizer | `ewww_settings` (site) manages optimization, resizing, WebP, and lazy loading. `ewww_attachment` accepts a numeric image attachment ID and the action optimize. |
| Yoast SEO | `yoast_settings`, `yoast_post`, and `yoast_term` manage supported settings and metadata. Read `yoast_ability` without an identifier to list registered abilities, then read a named ability to get its input schema. Execute read-only abilities with `query.input` and write abilities with `body.input`. |
| Feedback Widget for Snootl | `snootl_widget` (site) manages widget visibility, minimum role, dashboard display, and the write-only API key. |
| SyncPort — Migration & Sync | `syncport_settings` (site) manages push and pull permissions. `syncport_connections` lists, updates, and deletes named connections. `syncport_operations` reads migration history. |
| justDev Support | `justdev_support` (site) manages the settings page, including content locking and environment notices, for users allowed by the plugin. |
| Lomnio API Connector | Read `lomnio` without an identifier to list sections. Update pages, endpoints, or credentials. Credentials accept one `api_token` or `webhook_secret` at a time, with `null` to clear it. Update sync with target set to project, units, floors, or all. Read project, units, floors, or logs for locally stored data. Unit and floor lists support `query.offset` and `query.limit`. |

SyncPort migration execution, Media Cloud bulk jobs, and WPML automatic translation jobs require the respective plugin workflows.

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

Open **Tools → MCP Connector** to view the change history. The history records the UTC time, environment, WordPress user, Application Password UUID, operation, target, outcome, and available before/after differences for MCP writes. Known sensitive fields and uploaded binary data are omitted. ACF Options Page password and credential fields are write-only for administrators. Long values are shortened. `pending` indicates that the operation started but its outcome could not be recorded.

## Scope

The connector covers the REST collections and native resources listed above. Additional application-specific settings can use the extension points in `wp-config.php`. Shell commands, WP-CLI execution, database maintenance, and filesystem-based deployments remain separate operations.
