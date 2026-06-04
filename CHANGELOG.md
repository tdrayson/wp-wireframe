# Changelog

## [1.0.6] - 2026-06-04

### Features
- Role-based access control via optional `access` keys on tabs / sections / fields (strictly opt-in).
- Submenu pages via the new `parent` boot option (#8).
- New `action` field type — buttons that POST in-flight form values to a per-button hook (#9).
- `TableField` dispatch is hooks-first with callback fallback for back-compat (#9).
- Default prose styling on the `html` field (#8).
- Conditions DSL now works at the tab level.
- Repeater subfields support the conditions DSL (#13).
- Suppress third-party admin notices on Wireframe-managed pages — filterable via `wp-wireframe/suppress_admin_notices`.

### Fixes
- Resolve admin assets correctly on Windows (#1).
- Honour `menu_slug` in single-page `App::boot()` (#5, reported by @davidofchatham).
- Wrap React root in `SlotFillProvider` (#4, reported by @davidofchatham) — silences the warning and fixes popover positioning.
- Anchor admin-screen matching on `_page_{menu_slug}` suffix instead of substring (#6, reported by @davidofchatham).
- `DateField` falls back to a sensible format when WP's date format setting is empty (#3, thanks to @drkskwlkr).

## [1.0.5] - 2026-04-23

### Features
- `App::assetsUrl()` resolves URLs for mu-plugins, themes, and anywhere under `WP_CONTENT_DIR`.

### Fixes
- `TableField` bulk-action icon: swap nonexistent `star` for `starFilled`.

## [1.0.4] - 2026-04-23

### Features
- `.wp-list-table` styling scoped to `.wireframe-page`.
- New `assets_url` boot key for environments where `plugins_url()` can't derive the URL.

### Fixes
- Cleaner table detail view layout.
- Fixed fabricated WPDS token names in SCSS.

## [1.0.3] - 2026-04-23

### Features
- `{field_id}` token interpolation in field `description`.

### Fixes
- Solid background on copyable inputs' copy button.
- Bulk-action buttons in `table` field always resolve an icon.
