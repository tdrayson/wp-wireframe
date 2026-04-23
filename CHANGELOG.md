# Changelog

## [1.0.3] - 2026-04-23

### Features
- Field `description` text now supports `{field_id}` tokens that interpolate live values from the form, e.g. `'URL: https://example.com/{slug}'`.
- Shared `interpolate()` util in `js/utils/interpolate.js`; `RepeaterEdit` row title templates now use it too (replacing a duplicated inline regex).

### Fixes
- Copy button on copyable input fields now has a solid `var(--wpds-color-bg-surface-neutral-strong)` background so it no longer clashes with the input value behind it.
- DataViews bulk action buttons in the `table` field now always resolve an icon (with a generic fallback), since DataViews silently omits buttons that lack one.

### Notes
- Existing descriptions without `{...}` tokens are unaffected — `interpolate()` short-circuits when no `{` is present.
