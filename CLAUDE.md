# CLAUDE.md - JezPress Manager

## Project Overview

**Plugin Name:** JezPress Manager
**Slug:** `jezpress-manager`
**Version:** 1.1.0
**Type:** WordPress Admin Dashboard Plugin
**Purpose:** Central dashboard for managing all JezPress plugins, licenses, and support
**License Required:** No (free plugin, no license validation)

## JezPress Platform Integration

This plugin uses JezPress for automatic updates from updates.jezpress.com

### Quick Reference

| Service | URL |
|---------|-----|
| Update Server | https://updates.jezpress.com |
| MCP Server | https://mcp.jezpress.com/mcp |
| CLI | npm install -g @jezweb/jezpress-cli |

### Commands

```bash
jezpress login                    # Authenticate with Google
jezpress whoami                   # Check current user
jezpress plugins list --mine      # List your plugins
jezpress plugins get <slug>       # Plugin details
jezpress plugins preflight <slug> <zip>  # Validate before upload
jezpress plugins upload <slug> <zip>     # Upload new version
```

### Version Workflow

1. Update Version in plugin header AND constant
2. Create ZIP: `zip -r jezpress-manager.zip jezpress-manager -x "*.git*" -x "*CLAUDE.md" -x "*PLAN.md"`
3. Validate: `jezpress plugins preflight jezpress-manager ./jezpress-manager.zip`
4. Upload: `jezpress plugins upload jezpress-manager ./jezpress-manager.zip`

## Architecture

### File Structure

```
jezpress-manager/
├── jezpress-manager.php                    # Main plugin bootstrap
├── includes/
│   ├── class-jezpress-manager.php          # Core singleton class
│   └── class-jezpress-manager-updater.php  # JezPress updater class
├── readme.txt                              # WordPress.org readme
├── README.md                               # GitHub documentation
└── CLAUDE.md                               # This file
```

### Design Pattern

- **Singleton Pattern** - Single instance via `JezPress_Manager::instance()`
- **Static Registry** - Plugins register via `JezPress_Manager::register()`
- **Hook-based Initialization** - Loads on `plugins_loaded` priority 5

### Key Constants

```php
JEZPRESS_MANAGER_VERSION      // Plugin version
JEZPRESS_MANAGER_PLUGIN_FILE  // Main plugin file path
JEZPRESS_MANAGER_PLUGIN_PATH  // Plugin directory path
JEZPRESS_MANAGER_PLUGIN_URL   // Plugin URL
JEZPRESS_MANAGER_ACTIVE       // Boolean true - use for quick availability check
```

### Menu Structure

```
JezPress Manager (menu slug: jezpress-manager)
├── Dashboard (default submenu)
└── [Registered plugin submenus]
```

## Code Standards

### WordPress Coding Standards

- PHP 8.2+ required
- WordPress 6.0+ required
- Follow WordPress PHP Coding Standards
- Use proper escaping: `esc_html()`, `esc_url()`, `esc_attr()`
- Use proper sanitization: `sanitize_key()`, `sanitize_text_field()`
- Text domain: `jezpress-manager`

### Security Requirements

- All files must have `defined( 'ABSPATH' ) || exit;`
- Sanitize all input in `register()` method
- Escape all output in templates
- Capability checks on all admin pages (`manage_options`)

## Plugin Registration API

### How Other Plugins Register

```php
add_action( 'plugins_loaded', 'my_plugin_register', 15 );

function my_plugin_register() {
    // Quick check using constant
    if ( ! defined( 'JEZPRESS_MANAGER_ACTIVE' ) ) {
        return;
    }

    JezPress_Manager::register( array(
        'slug'           => 'my-plugin-settings',           // Required
        'name'           => 'My Plugin',                    // Required
        'version'        => '1.0.0',                        // Required
        'menu_title'     => 'My Plugin',                    // Required
        'capability'     => 'manage_options',               // Optional (default: manage_options)
        'callback'       => 'render_function',              // Optional
        'license_status' => 'active',                       // Optional
        'license_expiry' => '2025-12-31',                   // Optional
        'description'    => 'Plugin description',           // Optional
        'icon'           => 'dashicons-printer',            // Optional (default: dashicons-admin-plugins)
        'position'       => 10,                             // Optional (default: 10, lower = higher in menu)
    ) );
}
```

### Deregister a Plugin

```php
JezPress_Manager::deregister( 'my-plugin-slug' );
```

### Registration Priority

- JezPress Manager loads at priority **5**
- Other plugins should register at priority **15** or later
- This ensures the manager class is available

### License Status Values

| Value | Badge Color | Description |
|-------|-------------|-------------|
| `active` | Green | Valid license |
| `expired` | Yellow | Expired license (shows renew link) |
| `invalid` | Red | Invalid license key |
| `''` | Gray | No license configured |

## Development Guidelines

### Adding New Features

1. Keep the plugin lightweight - it's a dashboard, not a framework
2. New features should benefit all JezPress plugins
3. Maintain backward compatibility with existing registrations

### Modifying the Dashboard

- Dashboard HTML is in `render_dashboard()` method
- Styles are inline via `get_inline_styles()` method
- Consider extracting to separate CSS file if styles grow

### Adding New Registration Parameters

1. Add default value in `register()` method defaults array
2. Add sanitization for the new parameter
3. Update documentation in README.md and CLAUDE.md
4. Update PHPDoc in the `register()` method

## Testing Checklist

### Before Release

- [ ] Plugin activates without errors
- [ ] Dashboard displays correctly with no plugins registered
- [ ] Dashboard displays correctly with plugins registered
- [ ] All external links work and open in new tab
- [ ] License badges display correctly for all statuses
- [ ] Settings buttons link to correct submenu pages
- [ ] Responsive layout works on smaller screens
- [ ] No PHP warnings or notices

### Registration Testing

- [ ] Plugins can register with minimal parameters
- [ ] Plugins can register with all parameters
- [ ] Invalid/empty slugs are rejected
- [ ] Sanitization works correctly on all fields
- [ ] Callbacks are validated before adding submenu

## Common Tasks

### Change Support Email

Edit `render_dashboard()` in `class-jezpress-manager.php`:
```php
<a href="mailto:jez@jezweb.net">jez@jezweb.net</a>
```

### Change Menu Icon

Edit `add_admin_menu()` in `class-jezpress-manager.php`:
```php
'dashicons-superhero-alt', // Change to any dashicon
```

### Change Menu Position

Edit `add_admin_menu()` in `class-jezpress-manager.php`:
```php
58 // Menu position (after Appearance=60)
```

### Add New Quick Link

Add to the quick links section in `render_dashboard()`:
```php
<li>
    <a href="https://example.com" target="_blank" rel="noopener">
        <span class="dashicons dashicons-icon-name"></span>
        <?php esc_html_e( 'Link Text', 'jezpress-manager' ); ?>
    </a>
</li>
```

## Dependencies

- None (standalone plugin)
- Optional: Other JezPress plugins that register with it

## ZIP Structure (Critical)

```
jezpress-manager.zip
└── jezpress-manager/                       ← Folder MUST match slug
    ├── jezpress-manager.php                ← Main file MUST be slug.php
    ├── includes/
    │   ├── class-jezpress-manager.php
    │   └── class-jezpress-manager-updater.php
    ├── readme.txt
    ├── README.md
    └── uninstall.php
```

## Google Chat Notifications

When asked to "send release to Google Chat" or "notify Google Chat about release", use the **Jezweb Releases** webhook. The URL (with API key + space token) is a secret — do NOT commit it here. Retrieve it from `.jez/secrets/google-chat-webhooks.md` (or ask Jez) and use it as `WEBHOOK_URL` below.

### How to Send Release Notification

1. Extract changelog for the version from `readme.txt`
2. Send a POST request with a formatted card:

```bash
curl -X POST "WEBHOOK_URL" \
  -H "Content-Type: application/json" \
  -d '{
    "cardsV2": [{
      "cardId": "release-VERSION",
      "card": {
        "header": {
          "title": "JezPress Manager",
          "subtitle": "Version VERSION Released"
        },
        "sections": [{
          "header": "What'\''s New",
          "widgets": [{
            "textParagraph": {
              "text": "CHANGELOG_CONTENT"
            }
          }]
        }, {
          "widgets": [{
            "buttonList": {
              "buttons": [{
                "text": "View Plugin",
                "onClick": {
                  "openLink": {
                    "url": "https://admin.jezpress.com/dashboard/plugins/jezpress-manager"
                  }
                }
              }, {
                "text": "Download Latest",
                "onClick": {
                  "openLink": {
                    "url": "https://admin.jezpress.com/api/update-server/plugins/jezpress-manager/download?version=VERSION"
                  }
                }
              }]
            }
          }]
        }]
      }
    }]
  }'
```

## Hooks Reference

### Actions Used

| Hook | Priority | Purpose |
|------|----------|---------|
| `plugins_loaded` | 5 | Initialize plugin |
| `admin_menu` | 9 | Register admin menus |
| `admin_enqueue_scripts` | 10 | Load admin styles |
| `admin_init` | 10 | Handle activation redirect |

### Activation/Deactivation

- `register_activation_hook` - Sets version option and redirect transient
- `register_deactivation_hook` - Cleanup (currently empty)

## Troubleshooting

### Plugin Not Appearing in Menu

1. Check if `JezPress_Manager` class exists before registering
2. Verify registration happens after priority 5
3. Ensure slug is not empty after sanitization
4. Check capability requirements

### Styles Not Loading

1. Verify page hook contains `jezpress-manager` or `jezpress`
2. Check browser console for CSS errors
3. Clear any caching plugins

### Activation Redirect Not Working

1. Transient may have expired (30 second window)
2. Multi-plugin activation bypasses redirect
3. Check for redirect conflicts with other plugins
