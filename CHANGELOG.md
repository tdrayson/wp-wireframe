# Changelog

## [1.1.0] - 2026-10-12

### Features
- `{prefix}/can_save` / `{prefix}/can_reset` filters, enforced by the REST endpoints; Save and Reset hide on stateless-only pages (#36).
- `header_actions` page config key: link or dispatched buttons beside Save/Reset, via `{prefix}/header_action/{pageId}/{id}` (#37).
- `editor` field accepts native `wp.editor` settings via `args`: `tinymce`, `quicktags`, `media_buttons`, `wpautop`, `rows` (#19).
- New `upload` field: temporary local files passed to actions, never stored in the media library (#21).
- Actions can return a `download` payload; `table` fields export the full dataset to CSV (#23).
- `menu_icon` accepts a custom SVG, inline or as a file path (#29).
- Tabs and sections without their own `access` key are shown only when the user can view one of their fields (#30).
- JSON Schema for page configs, plus a runtime config linter under `WP_DEBUG` (#31).
- **Behaviour change:** the `action` field's label spacer is now opt-in via `args.label_spacer` (#42).

### Fixes
- Media picker pre-selects the stored attachments when opened (#17).
- Swap abandoned `rakit/validation` for its maintained fork `magewirephp/validation`, silencing PHP 8.4 deprecation notices (#33).
- `editor` subfields initialise in every repeater row and survive collapse, reorder and removal (#38).
- Plugins bundling the release zip no longer fatal on a shared Composer autoloader class; every copy registers and the newest one's classes load (#35, reported by @christianvanthof).
- Action and header action handlers only receive values for fields the user can view; view-only fields carry the stored value, not the posted one (#51).

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
