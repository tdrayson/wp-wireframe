# Changelog

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
