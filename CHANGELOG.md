# Changelog

## [1.1.0] - 2026-05-21

### Features
- **Role-based access control**: optional `access` key on tabs, sections, and fields. Two verbs (`view`, `edit`) accepting role slugs or capabilities; arrays evaluate as OR. Tabs and sections auto-hide when every descendant is filtered out. Non-editable but viewable fields render disabled. Strictly opt-in — pages without any `access` key behave exactly as before (`manage_options` required for everything).
- **Conditional tabs**: tabs now support the same `conditions` DSL as sections and fields, evaluated against current form values.
- **Save merges instead of overwrites**: the REST save endpoint now intersects the payload with the user's editable field list and merges into existing saved state. A partial-access user can no longer wipe fields outside their scope.
- **Scoped reset**: the REST reset endpoint now only resets fields the user can edit. For admins with full access this is equivalent to a full reset; for partial-access users it's bounded to their slice.
- New filters: `wp-wireframe/access/resolve`, `wp-wireframe/access/can_reset`, `wp-wireframe/config/for_user`, `wp-wireframe/save/editable_fields`, `wp-wireframe/save/payload`.
- New classes: `Wireframe\Framework\Access\AccessResolver` and `Wireframe\Framework\Access\ConfigAccessMap`.
- Worked example added to the Field Reference example plugin (new "Role-Based Access" tab).

### Design Rationale
- **Strictly opt-in** was a deliberate choice: a page config with zero `access` keys produces byte-identical behavior to previous versions. The `AccessResolver::pageMode()` walk runs once at boot to decide whether to engage the new pipeline at all. This guarantees no plugin can accidentally widen access by upgrading.
- **Merge instead of overwrite** on save replaces the old `preserveHiddenFieldValues` pass — preservation now falls out of the merge for free, and the same code path handles both condition-hidden and access-restricted fields consistently.
- **Strip non-viewable elements from the config** sent to the browser, rather than rendering placeholders, so partial-access users have no idea hidden fields exist. The reset confirmation dialog uses neutral wording ("Reset the settings on this page to their defaults?") for the same reason.
- **Capability is the primitive, role slugs are sugar**: strings are matched against role slugs first (via `get_role()`), falling back to capability lookups (`user_can`). This keeps configs readable (`'editor'`) without sacrificing the flexibility of custom capabilities.

### Notes & Caveats
- Custom field types should honor the new `field.readOnly` prop from `mapField()`. If a custom field ignores it, the server still rejects writes — the prop is a UI hint only.
- Repeater subfields don't get their own `access` keys (would be confusing UX). The whole repeater is one editable unit; subfields inherit the parent's editability.
- The Reset button label stays "Reset" (not "Reset my fields") for partial-access users — the UX uses inline confirm buttons rather than a popup, and neutral labels avoid leaking the existence of hidden fields.

## [1.0.5] - 2026-04-23

### Features
- `App::assetsUrl()` now resolves URLs when the package is installed outside `WP_PLUGIN_DIR` — mu-plugins (`WPMU_PLUGIN_DIR`), active/parent theme, or anywhere under `WP_CONTENT_DIR`. Roots are checked in priority order; the first to contain the package wins.
- Filesystem comparisons go through `realpath()` on both ends, so symlinked installs (e.g. the package symlinked into a theme or into `vendor/` during local dev) still match.

### Fixes
- `TableField` bulk-action icon map: `star` isn't exported from `@wordpress/icons` — swapped for the real `starFilled` export, so a `"star-filled"` / `"starFilled"` action icon now resolves instead of silently falling back to `cog`.

### Design Rationale
- Previously `assetsUrl()` only handled the `WP_PLUGIN_DIR` case and silently returned `''` for theme/mu-plugin installs, forcing consumers to pass `assets_url` manually. The explicit `assets_url` override from 1.0.4 still takes precedence for edge cases this heuristic can't cover.

## [1.0.4] - 2026-04-23

### Features
- New `.wp-list-table` styling scoped to `.wireframe-page`. Render-callback HTML can now include `<table class="wp-list-table widefat fixed striped">` and pick up framework-styled tables (rounded border, neutral header, hover highlight, optional `.is-compact` and `.striped` modifiers) without leaking into other WP admin screens.
- `App::boot()` now accepts an `assets_url` config key for environments where the package lives outside `WP_PLUGIN_DIR` (e.g. symlinked into `vendor/` during local development), where `plugins_url()` can't derive the URL on its own.

### Fixes
- Removed the divider borders above/below the action footer and below the title in the table detail view for a cleaner card layout.
- All SCSS WPDS token references now resolve — replaced fabricated names (`--wpds-color-bg-surface`, `--wpds-color-text-default`, `--wpds-dimension-radius-sm`, etc.) with their actual counterparts (`--wpds-color-bg-surface-neutral-strong`, `--wpds-color-fg-content-neutral`, `--wpds-border-radius-md`, …).

## [1.0.3] - 2026-04-23

### Features
- Field `description` text now supports `{field_id}` tokens that interpolate live values from the form, e.g. `'URL: https://example.com/{slug}'`.
- Shared `interpolate()` util in `js/utils/interpolate.js`; `RepeaterEdit` row title templates now use it too (replacing a duplicated inline regex).

### Fixes
- Copy button on copyable input fields now has a solid `var(--wpds-color-bg-surface-neutral-strong)` background so it no longer clashes with the input value behind it.
- DataViews bulk action buttons in the `table` field now always resolve an icon (with a generic fallback), since DataViews silently omits buttons that lack one.

### Notes
- Existing descriptions without `{...}` tokens are unaffected — `interpolate()` short-circuits when no `{` is present.
