![WP Wireframe Thumbnail](docs/featured-image.png)

# WP Wireframe

**Skip the admin UI. Ship your plugin.**

A fast, standardised way to build WordPress settings pages. List your fields in a PHP array and WP Wireframe builds the whole admin page using WordPress react components. Same clean UI, same field behaviour, same patterns across every plugin you ship.

---

## Features

- **One PHP array, full settings page** — tabs, sections, and 20+ field types
- **Standardised fields** — every input looks and behaves the same across plugins and projects
- **Native WordPress look** — built on `@wordpress/components` and `@wordpress/admin-ui`
- **Laravel-style API** — `Settings::get()`, `Settings::bool()`, dot notation
- **Validation & conditional fields** — server-side rules and show/hide logic built right into the field config
- **Multi-page, repeaters, import/export, i18n** — included out of the box
- **Zero JS build** — pre-built React app ships with the package

---

## Demo Settings UI

An example of what the settings page looks like

<img width="1558" height="3191" alt="plugin-settings-demo" src="https://github.com/user-attachments/assets/c8f062bd-ca9d-4054-b6f0-54b50ee91a62" />

---

## Example plugins

Working starting points you can copy and adapt:

- [Field Reference](./examples/field-reference) — kitchen-sink demo of every field type, plus an "Example" tab that reads like a real product settings page
- [Newsletter Signup](./examples/newsletter-signup) — config loaded from `config/settings.php`
- [QuickChat](./examples/quickchat) — SaaS-style plugin with its entire config inline in one file

---

## Requirements

- PHP 8.1+
- WordPress 6.5+

---

## Installation

WP Wireframe is a library, not a standalone plugin. Bundle a copy inside your own plugin's `vendor/` folder.

### Option 1: Composer (recommended)

Add the GitHub repo to your plugin's `composer.json` — no Packagist needed:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/tdrayson/wp-wireframe"
        }
    ],
    "require": {
        "tdrayson/wp-wireframe": "^1.0"
    }
}
```

Then install:

```bash
composer install
```

And require Composer's autoloader from your plugin's main file:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

Composer resolves the latest tagged release. The compiled JS/CSS bundle ships with the package, so there's no build step on your side.

### Option 2: Manual download

1. Download the [latest release zip](https://github.com/tdrayson/wp-wireframe/releases/latest) and extract it so the folder sits at `your-plugin/vendor/wp-wireframe/`.
2. Require the autoloader from your plugin's main file:

```php
require_once __DIR__ . '/vendor/wp-wireframe/vendor/autoload.php';
```

---

That's it — no separate plugin to install or activate. If multiple plugins each bundle their own copy, they share one class definition at runtime and boot independently under their own prefixes.

---

## Quick Start

### 1. Create your plugin file

```php
<?php
/**
 * Plugin Name: My Plugin
 * Description: A plugin with settings powered by WP Wireframe.
 * Version:     1.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

// Composer autoloader — use the manual path
// (vendor/wp-wireframe/vendor/autoload.php) if you installed without Composer.
require_once __DIR__ . '/vendor/autoload.php';

add_action('init', function () {
    Wireframe\App::boot([
        'prefix'     => 'my-plugin',
        'page_title' => __('My Plugin', 'my-plugin'),
        'option_key' => 'my_plugin_settings',
        'config'     => __DIR__ . '/config/settings.php',
    ]);
});
```

### 2. Create `config/settings.php`

```php
<?php

return [
    'tabs' => [
        [
            'id'       => 'general',
            'title'    => __('General', 'my-plugin'),
            'sections' => [
                [
                    'id'          => 'main',
                    'title'       => __('Main Settings', 'my-plugin'),
                    'description' => __('Configure the basics.', 'my-plugin'),
                    'fields'      => [
                        [
                            'id'       => 'site_name',
                            'type'     => 'text',
                            'label'    => __('Site Name', 'my-plugin'),
                            'required' => true,
                            'columns'  => 6,
                            'args'     => ['placeholder' => __('My Website', 'my-plugin')],
                        ],
                        [
                            'id'      => 'contact_email',
                            'type'    => 'email',
                            'label'   => __('Contact Email', 'my-plugin'),
                            'columns' => 6,
                        ],
                        [
                            'id'          => 'notifications',
                            'type'        => 'toggle',
                            'label'       => __('Enable Notifications', 'my-plugin'),
                            'description' => __('Send alerts when settings change.', 'my-plugin'),
                            'default'     => true,
                        ],
                    ],
                ],
            ],
        ],
    ],
];
```

### 3. Use settings in your plugin

Every `Settings::` call takes your plugin's `option_key` as the first argument, so multiple plugins sharing the framework can't collide.

```php
use Wireframe\Settings;

$name  = Settings::get('my_plugin_settings', 'site_name', 'Default');
$email = Settings::string('my_plugin_settings', 'contact_email');

if (Settings::bool('my_plugin_settings', 'notifications')) {
    // send notification...
}
```

That's it. Activate your plugin and the settings page appears in the admin menu.

---

## Plugin Structure

WP Wireframe lives inside your plugin as a bundled dependency:

```
my-plugin/
├── my-plugin.php              # Boot call (~15 lines)
├── config/
│   └── settings.php           # Field definitions (or pass inline)
└── vendor/
    └── wp-wireframe/          # Extracted from the release zip
        ├── src/
        └── vendor/            # rakit/validation
```

---

## Configuration

### Boot Options

```php
Wireframe\App::boot([
    'prefix'        => 'my-plugin',                         // Required. Derives slugs, hooks, handles.
    'page_title'    => 'My Plugin',                         // Admin menu label.
    'option_key'    => 'my_plugin_settings',                // wp_options key for storage.
    'config'        => __DIR__ . '/config/settings.php',    // Path to a config file — OR an inline array.
    'version'       => '1.0.0',                             // Plugin version.
    'menu_icon'     => 'dashicons-admin-generic',
    'menu_position' => 80,
    'capability'    => 'manage_options',
]);
```

Everything except `prefix` has a sensible default. `config` accepts either a PHP file path or an inline array — see [Config Structure](#config-structure).

### Multi-Page

Register multiple admin pages from a single plugin:

```php
Wireframe\App::boot([
    'prefix'     => 'my-plugin',
    'option_key' => 'my_plugin',
    'pages'      => [
        [
            'page_title' => __('General', 'my-plugin'),
            'config'     => __DIR__ . '/config/general.php',
            'menu_icon'  => 'dashicons-admin-generic',
        ],
        [
            'page_title' => __('Advanced', 'my-plugin'),
            'config'     => __DIR__ . '/config/advanced.php',
            'menu_icon'  => 'dashicons-admin-tools',
        ],
    ],
]);
```

Each page's `config` accepts either a file path or an inline array. Each page gets its own option key, REST endpoint, and admin menu entry.

---

## Config Structure

Fields use a flat structure with promoted common keys. Type-specific settings go in `args`.

```php
return [
    'title'    => 'Page Title',              // Optional. Shown in the header.
    'subtitle' => 'A short description',     // Optional. Shown below the title.
    'tabs'     => [
        [
            'id'       => 'tab_id',
            'title'    => 'Tab Label',
            'sections' => [
                [
                    'id'          => 'section_id',
                    'title'       => 'Section Title',
                    'description' => 'Helpful description text.',
                    'fields'      => [
                        [
                            'id'          => 'field_id',
                            'type'        => 'text',
                            'label'       => 'Field Label',
                            'description' => 'Help text below the field.',
                            'default'     => '',
                            'required'    => false,
                            'validation'  => 'min:3|max:100',
                            'columns'     => 6,              // 1-12 grid columns
                            'conditions'  => [                // Conditional visibility
                                'all' => [
                                    ['field' => 'other_field', 'operator' => 'truthy'],
                                ],
                            ],
                            'args' => [                       // Type-specific settings
                                'placeholder' => 'Enter text',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
```

### Promoted keys (field level)

These are common to all field types:

| Key           | Description                         | Default  |
| ------------- | ----------------------------------- | -------- |
| `id`          | Unique field identifier             | Required |
| `type`        | Field type                          | `'text'` |
| `label`       | Display label                       | `''`     |
| `description` | Help text below the field. Supports `{field_id}` tokens that interpolate live values (e.g. `'URL: https://example.com/{slug}'`). | `''`     |
| `default`     | Default value                       | `null`   |
| `required`    | Whether the field must have a value | `false`  |
| `validation`  | Rakit validation rules string       | `''`     |
| `columns`     | Grid width (1-12)                   | `12`     |
| `conditions`  | Conditional visibility rules        | `null`   |

### Type-specific settings (`args`)

Settings unique to specific field types — `placeholder`, `rows`, `options`, `multiple`, `subfields`, `mode`, `mime_types`, etc.

### Flexible Structure

Tabs and sections are optional:

```php
// Simplest — just fields (no tabs, no sections)
return [
    'fields' => [
        ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
        ['id' => 'email', 'type' => 'email', 'label' => 'Email'],
    ],
];

// With sections, no tabs
return [
    'sections' => [
        [
            'id'     => 'main',
            'title'  => 'Main Settings',
            'fields' => [
                ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
            ],
        ],
    ],
];
```

No tabs = no tab bar. No sections = fields render in a single card.

---

## Field Types

### Text Family

| Type       | Description                       | Stored as |
| ---------- | --------------------------------- | --------- |
| `text`     | Single-line text input            | `string`  |
| `email`    | Email with format validation      | `string`  |
| `url`      | URL with format validation        | `string`  |
| `password` | Masked input                      | `string`  |
| `textarea` | Multi-line text                   | `string`  |
| `hidden`   | Not rendered, round-trips a value | `string`  |

### Choice

| Type         | Description                                                         | Stored as           |
| ------------ | ------------------------------------------------------------------- | ------------------- |
| `select`     | Dropdown. Add `'multiple' => true` in args for searchable tag input | `string` or `array` |
| `radio`      | Radio button group                                                  | `string`            |
| `checkboxes` | Multiple checkboxes                                                 | `array`             |
| `toggle`     | On/off switch                                                       | `bool`              |
| `checkbox`   | Single checkbox                                                     | `bool`              |

### Numeric

| Type     | Description                     | Stored as        |
| -------- | ------------------------------- | ---------------- |
| `number` | Numeric input with min/max/step | `int` or `float` |
| `range`  | Slider with tooltip             | `int` or `float` |

### Date, Time & Color

| Type    | Description                     | Stored as             |
| ------- | ------------------------------- | --------------------- |
| `date`  | Date picker (dropdown calendar) | `string` (YYYY-MM-DD) |
| `time`  | Time input (24h)                | `string` (HH:MM)      |
| `color` | Color swatch + picker dropdown  | `string` (#hex)       |

### Rich Content

| Type          | Description                         | Stored as       |
| ------------- | ----------------------------------- | --------------- |
| `editor`      | TinyMCE WYSIWYG editor              | `string` (HTML) |
| `code_editor` | CodeMirror with syntax highlighting | `string`        |

Supported code modes: `css`, `js`, `html`, `php`, `json`, `xml`, `sql`

### Media

| Type   | Description                                                             | Stored as                |
| ------ | ----------------------------------------------------------------------- | ------------------------ |
| `file` | Media Library picker. Add `'multiple' => true` in args for multi-select | `array` (attachment IDs) |

### Complex

| Type       | Description                          | Stored as          |
| ---------- | ------------------------------------ | ------------------ |
| `repeater` | Add/remove/reorder rows of subfields | `array` of objects |

### Display & Actions (stateless)

| Type     | Description                                                      | Stored as |
| -------- | ---------------------------------------------------------------- | --------- |
| `html`   | Read-only display block (info, success, warning, error variants) | —         |
| `export` | Download settings as JSON                                        | —         |
| `import` | Upload JSON to restore settings                                  | —         |

---

## Field Examples

### Select with Multi-Select

```php
[
    'id'      => 'categories',
    'type'    => 'select',
    'label'   => 'Categories',
    'default' => ['news'],
    'columns' => 6,
    'args'    => [
        'multiple' => true,
        'options'  => [
            'news'    => 'News',
            'blog'    => 'Blog',
            'reviews' => 'Reviews',
        ],
    ],
],
```

### Repeater

```php
[
    'id'    => 'redirects',
    'type'  => 'repeater',
    'label' => 'Redirects',
    'args'  => [
        'sortable'       => true,
        'collapsible'    => true,
        'duplicate_row'  => true,
        'max_rows'       => 50,
        'add_label'      => 'Add redirect',
        'empty_message'  => 'No redirects configured.',
        'title_template' => '{from} → {status}',
        'subfields'      => [
            [
                'id'       => 'from',
                'type'     => 'text',
                'label'    => 'From path',
                'required' => true,
                'columns'  => 4,
            ],
            [
                'id'       => 'to',
                'type'     => 'text',
                'label'    => 'To URL',
                'required' => true,
                'columns'  => 4,
            ],
            [
                'id'      => 'status',
                'type'    => 'select',
                'label'   => 'Status',
                'default' => '301',
                'columns' => 4,
                'args'    => [
                    'options' => [
                        '301' => '301 Permanent',
                        '302' => '302 Temporary',
                    ],
                ],
            ],
        ],
    ],
],
```

### Conditional Visibility

```php
[
    'id'      => 'debug_mode',
    'type'    => 'toggle',
    'label'   => 'Debug Mode',
    'default' => false,
],
[
    'id'         => 'log_level',
    'type'       => 'select',
    'label'      => 'Log Level',
    'default'    => 'error',
    'columns'    => 6,
    'conditions' => [
        'all' => [
            ['field' => 'debug_mode', 'operator' => 'truthy'],
        ],
    ],
    'args' => [
        'options' => [
            'error' => 'Errors only',
            'info'  => 'Info & above',
            'debug' => 'Everything',
        ],
    ],
],
```

**Supported condition operators:** `equals`, `not_equals`, `truthy`, `falsy`, `in`, `not_in`, `contains`, `not_contains`, `starts_with`, `ends_with`, `is_empty`, `is_not_empty`, `gt`, `gte`, `lt`, `lte`, `between`

Combine with `all` (AND) or `any` (OR).

### Validation

```php
[
    'id'         => 'username',
    'type'       => 'text',
    'label'      => 'Username',
    'required'   => true,
    'validation' => 'min:3|max:20|regex:/^[a-z0-9_]+$/',
],
```

Built-in validation is automatic per field type (email format, URL format, numeric bounds, color hex, date/time format). Add custom rules via the `validation` key using [rakit/validation](https://github.com/rakit/validation) syntax.

### Layout Grid

Fields use a 12-column grid. Set `columns` to control width:

```php
['id' => 'field_a', 'type' => 'text', 'label' => 'Half width',  'columns' => 6],
['id' => 'field_b', 'type' => 'text', 'label' => 'Full width',  'columns' => 12],
['id' => 'field_c', 'type' => 'text', 'label' => 'Third width', 'columns' => 4],
```

---

## Config Helpers

Programmatically manipulate a config array before passing it to `App::boot()`:

```php
use Wireframe\Config;

// Add a field after another
$config = Config::addFieldAfter('site_name', [
    'id' => 'subtitle', 'type' => 'text', 'label' => 'Subtitle',
], $config);

// Add a field before another
$config = Config::addFieldBefore('email', [
    'id' => 'prefix', 'type' => 'text', 'label' => 'Prefix',
], $config);

// Modify an existing field
$config = Config::modifyField('site_name', [
    'required' => true,
    'validation' => 'min:5',
], $config);

// Remove a field
$config = Config::removeField('tagline', $config);

// Add a section to a tab
$config = Config::addSection('general', [
    'id' => 'social', 'title' => 'Social Links', 'fields' => [...],
], $config);

// Add or remove tabs
$config = Config::addTab(['id' => 'integrations', 'title' => 'Integrations', 'sections' => [...]], $config);
$config = Config::removeTab('advanced', $config);
$config = Config::removeSection('deprecated', $config);
```

Ideal for building up an inline config you pass to `App::boot()`, or for loading a base config file and mutating it before boot:

```php
$config = require __DIR__ . '/config/settings.php';

$config = Config::addFieldAfter('site_name', [
    'id' => 'powered_by', 'type' => 'text', 'label' => 'Powered By',
], $config);

Wireframe\App::boot([
    'prefix' => 'my-plugin',
    'config' => $config,
]);
```

---

## Settings API

Every `Settings::` call takes your plugin's `option_key` as the first argument. This makes the facade multi-tenant-safe: many plugins can share the same framework install without stepping on each other's saved options.

```php
use Wireframe\Settings;

$opt = 'my_plugin_settings';   // matches what you passed to App::boot()
```

### Reading

```php
Settings::get($opt, 'field_id');                // any value, with dot notation
Settings::get($opt, 'field_id', 'fallback');    // with default
Settings::get($opt, 'repeater.0.subfield');     // dot notation into repeaters

// Type-safe getters
Settings::bool($opt, 'notifications');          // always bool
Settings::int($opt, 'max_posts');               // always int
Settings::float($opt, 'opacity');               // always float
Settings::string($opt, 'site_name');            // always string
Settings::array($opt, 'post_types');            // always array
Settings::json($opt, 'config_json');            // decoded JSON
```

### Writing

```php
Settings::set($opt, 'site_name', 'New Name');
Settings::toggle($opt, 'notifications');
Settings::increment($opt, 'view_count');
Settings::decrement($opt, 'credits', 5);
Settings::push($opt, 'allowed_ips', '10.0.0.1');
Settings::forget($opt, 'api_key');
```

### Checking

```php
Settings::has($opt, 'api_key');                 // exists and not null
Settings::exists($opt);                         // any settings saved at all
Settings::filled($opt, 'site_name', 'fallback'); // non-empty or fallback
```

### Subsets

```php
Settings::only($opt, 'site_name', 'email');     // just these keys
Settings::except($opt, 'api_key', 'password');  // everything except
Settings::all($opt);                            // raw saved values
Settings::resolved($opt);                       // merged with defaults
```

### Conditional Helpers

```php
Settings::when($opt, 'api_key', fn($key) => initService($key));
Settings::transform($opt, 'color', fn($c) => ltrim($c, '#'));
Settings::getOrSet($opt, 'counter', 0);
Settings::pull($opt, 'temp_token');             // get and remove
```

---

## Hooks & Filters

Per-plugin hooks fire under your own prefix:

```php
// After settings are saved
add_action('my-plugin/settings_saved', function (array $values, string $pageId) {
    // $values = sanitized settings array
}, 10, 2);

// After settings are reset
add_action('my-plugin/settings_reset', function (string $pageId) {
    // clear caches, etc.
});
```

The field type registry is shared across every plugin, so its filter is global:

```php
add_filter('wp-wireframe/field_types', function (array $types) {
    $types['my_custom'] = MyCustomField::class;
    return $types;
});
```

---

## Custom Field Types

Create a class extending `BaseField`:

```php
use Wireframe\Framework\Fields\BaseField;

class MyCustomField extends BaseField
{
    public static function type(): string
    {
        return 'my_custom';
    }

    public static function defaultRules(array $args): string
    {
        return 'required';  // Rakit validation rules
    }

    public static function sanitize(mixed $value, array $args): mixed
    {
        return sanitize_text_field($value);
    }

    public static function validate(mixed $value, array $args): ?string
    {
        // Return null if valid, or an error message string
        return null;
    }
}
```

Register via filter:

```php
add_filter('wp-wireframe/field_types', function (array $types) {
    $types['my_custom'] = MyCustomField::class;
    return $types;
});
```

---

## REST API

Settings are managed via REST endpoints:

| Method   | Endpoint                         | Description              |
| -------- | -------------------------------- | ------------------------ |
| `GET`    | `/{prefix}/v1/settings/{pageId}` | Get config + values      |
| `POST`   | `/{prefix}/v1/settings/{pageId}` | Validate, sanitize, save |
| `DELETE` | `/{prefix}/v1/settings/{pageId}` | Reset to defaults        |

All endpoints require `manage_options` capability (or your configured `capability`).

---

## Translation

WP Wireframe ships its own translations for UI strings (Save, Reset, Cancel, etc.) under the `wp-wireframe` text domain — these load automatically.

Your plugin's config strings use your own text domain:

```php
'label' => __('Site Name', 'my-plugin'),
```

Load your plugin's text domain the usual WordPress way (or rely on WordPress's auto-loading if your `.mo` files follow the `languages/{domain}-{locale}.mo` convention). WP Wireframe does not do this for you.

---

## Development

To work on the package itself:

```bash
git clone https://github.com/tdrayson/wp-wireframe.git
cd wp-wireframe
composer install
npm install
npm run start    # watch mode
npm run build    # production build → src/assets/
```

---

## License

GPL-2.0-or-later

---

## Credits

Built by [Taylor Drayson](https://github.com/tdrayson).

Uses [@wordpress/components](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-components/), [@wordpress/admin-ui](https://github.com/WordPress/gutenberg/tree/trunk/packages/admin-ui), and [rakit/validation](https://github.com/rakit/validation).
