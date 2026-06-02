# Role-Based Access Control — Planning Doc

Status: Draft
Branch: `feat/role-based-access`

## Goal

Allow plugin authors to restrict visibility and editability of tabs, sections, and fields by capability or role, with a developer-friendly filter layer for runtime overrides. The REST save path must respect access so a user with partial access cannot overwrite fields they don't control.

## Non-goals

- Multi-tenancy or per-post-type capability mapping.
- A UI for assigning access (this is configured in code).
- Audit logging of denied attempts (can come later).

---

## 1. Schema

Add an optional `access` key on **tabs**, **sections**, and **fields**. Two verbs: `view` and `edit`.

```php
'access' => [
    'view' => 'editor',           // role name or capability
    'edit' => 'manage_options',   // role name or capability
]
```

Shorthand forms:

```php
'access' => 'editor'                              // same string for view + edit
'access' => ['view' => 'editor']                  // edit inherits page capability
'access' => ['view' => ['editor', 'author']]      // any of these grants view
```

### Resolution rules

- If `access` is absent, element inherits from its parent (field ← section ← tab ← page capability).
- The page-level `capability` remains the default ceiling — and defaults to `manage_options` as it does today.
- A string is matched first against role slugs (`get_role()`), then treated as a capability.
- Arrays are evaluated as logical OR (user has *any* of these passes).
- `edit` implicitly requires `view`. If user lacks `view`, they cannot `edit` regardless.

### Opt-in principle (CRITICAL)

Role-based access is **strictly opt-in**. If a page config contains zero `access` keys anywhere in its tree, behavior is byte-for-byte identical to today: `manage_options` required for the menu, the REST endpoints, and every field. The new pipeline only activates when at least one `access` key is declared somewhere on the page.

This guarantees existing plugins cannot accidentally widen access by upgrading WP Wireframe.

---

## 2. Access Resolver

New class: `src/Framework/Access/AccessResolver.php`

```php
final class AccessResolver
{
    public function userCan(string $verb, array $accessSpec, ?WP_User $user = null): bool;
    public function resolveForConfig(array $config, ?WP_User $user = null): ConfigAccessMap;
}
```

`ConfigAccessMap` is a value object holding:
- `viewable: array<tabId, array<sectionId, array<fieldId>>>` — what shows in UI
- `editable: array<fieldId>` — flat list of field IDs the user may write
- `menuCapability: string` — the lowest-required cap across all viewable elements

Final yes/no for each `(verb, level, id, user)` passes through a filter so devs can override:

```php
apply_filters('wp-wireframe/access/resolve', bool $allowed, array $context)
// $context = ['verb' => 'view'|'edit', 'level' => 'tab'|'section'|'field', 'id' => string, 'user' => WP_User, 'spec' => array]
```

---

## 3. Config Filtering Pipeline

Happens once per request, before localization and before REST handlers run.

```
ConfigLoader::load()
  → AccessResolver::resolveForConfig($config, $user)
  → filter: wp-wireframe/config/for_user ($config, $user, $accessMap)
  → strip non-viewable tabs/sections/fields
  → auto-hide empty tabs (recursive: tab gone if all sections gone)
  → mark non-editable fields with `readonly: true`
  → localize to JS
```

### Tab auto-hide

After per-element access filtering, walk the tree:

- A section with zero remaining fields is dropped.
- A tab with zero remaining sections is dropped.

This is the same recursion shape used today when `conditions` hide elements, just applied earlier in the pipeline.

### Conditional tabs (bonus scope)

Today only fields and sections support `conditions`. Add `conditions` to the tab schema in `ConfigLoader::normalize()` and evaluate in `SettingsPage.js` alongside the existing field/section logic. Cheap addition while we're touching the pipeline.

---

## 4. Menu Capability

Two modes based on whether the page opts into role-based access:

**Mode A — No `access` keys anywhere on the page (default)**
Pass-through to current behavior: `add_menu_page(..., $page['capability'], ...)` where `capability` defaults to `manage_options`. Nothing changes for existing plugins.

**Mode B — Page declares at least one `access` key**
Drop the menu cap to `'read'` so the page is reachable for any logged-in user, then rely on the per-element filtering pipeline to hide what they can't see. Suppress `add_menu_page` entirely if `$accessMap->viewable` is empty for the current user.

The mode is detected once at registration time by walking the config tree for any `access` key. Result is cached per page config.

```php
$mode = AccessResolver::pageMode($pageConfig); // 'legacy' | 'rbac'
$cap  = $mode === 'rbac' ? 'read' : ($pageConfig['capability'] ?? 'manage_options');
if ($mode === 'rbac' && empty($accessMap->viewable)) {
    return; // skip menu registration for this user
}
add_menu_page(..., $cap, ...);
```

---

## 5. REST Save Flow

Current flow (`SettingsController::saveSettings`):
1. Load existing values
2. Merge payload over existing (for condition eval only)
3. Validate visible fields
4. Sanitize visible fields
5. Preserve hidden field values
6. `update_option()` full overwrite

New flow:

```
1. Build AccessMap for current user
2. Reject request if user has zero editable fields for this page (403)
3. Load existing values
4. Intersect payload with $accessMap->editable — drop any field the user can't edit
5. Validate conditions against (existing ∪ intersected payload)
6. Sanitize the intersected payload
7. Merge sanitized payload INTO existing values (not overwrite)
8. update_option() with merged result
```

This replaces `preserveHiddenFieldValues` — preservation becomes a natural consequence of merging instead of overwriting.

### New filters in save path

```php
// Final list of field IDs this user may write. Devs can prune further or add.
apply_filters('wp-wireframe/save/editable_fields', array $fieldIds, string $pageId, WP_User $user)

// Final payload after intersection + sanitization, before merge. Devs can transform values.
apply_filters('wp-wireframe/save/payload', array $payload, string $pageId, WP_User $user)
```

### Permission callback

`checkPermission()` behavior depends on page mode:

- **Legacy mode** (no `access` keys): unchanged — `current_user_can($page['capability'])`, defaulting to `manage_options`.
- **RBAC mode** (at least one `access` key): `current_user_can('read')` AND `$accessMap->editable` is non-empty. A 403 is returned if the user has zero editable fields, even if they can view some.

---

## 5a. Reset Flow

Reset mirrors save's merge semantics — it only resets fields the user can edit. For an admin with edit on every field this is functionally identical to "reset everything"; for a partial-access user it scopes the operation to their editable subset.

### Flow

```
1. Build AccessMap for current user
2. Check can_reset filter (default: true if $accessMap->editable is non-empty)
3. Reject with 403 if not allowed
4. Load existing values
5. For each fieldId in $accessMap->editable, remove key from existing values (or replace with field default if defined)
6. update_option() with the result
```

### Filter

```php
// Whether this user may reset on this page. Default: true if they have any editable fields.
apply_filters('wp-wireframe/access/can_reset', bool $can, string $pageId, WP_User $user, ConfigAccessMap $map)
```

### Confirmation dialog

Wording stays neutral so the dialog doesn't leak the existence of hidden fields to partial-access users (which would contradict the strip-non-viewable decision):

> "Reset the settings on this page to their defaults?"

For plugin authors who want explicit wording (e.g., SaaS dashboards where users *should* know they're scoped), override via:

```php
apply_filters('wp-wireframe/reset/confirm_message', string $message, string $pageId, WP_User $user, ConfigAccessMap $map)
```

### Frontend visibility

- Reset button hidden when `$accessMap->editable` is empty OR `can_reset` filter returns false.
- Save button hidden when `$accessMap->editable` is empty (read-only users see no action buttons).

---

## 6. Filter Surface (Summary)

| Hook | Type | Purpose |
|------|------|---------|
| `wp-wireframe/access/resolve` | filter | Override yes/no for a single (verb, level, id, user) check |
| `wp-wireframe/access/can_reset` | filter | Override whether a user may reset on a given page |
| `wp-wireframe/config/for_user` | filter | Mutate the full config after access filtering, before localize |
| `wp-wireframe/save/editable_fields` | filter | Final list of writable field IDs for a save request |
| `wp-wireframe/save/payload` | filter | Transform the sanitized payload before merge |
| `wp-wireframe/reset/confirm_message` | filter | Override the reset confirmation dialog text |

Existing hooks (`field_types`, `settings_saved`, `settings_reset`) stay as-is.

---

## 7. Rendering Changes

- `SettingsPage.js` — already iterates tabs from config; no change needed since stripped tabs simply aren't in the data.
- `SettingsSection.js` — already iterates fields; no change for stripped fields.
- Field components — need to honor a new `readonly: true` flag from config:
  - Inputs render with `disabled` attribute
  - Custom field types (table, repeater, etc.) need to opt into respecting `readonly` — document this in the field-author guide
  - Submit button stays enabled if *any* field on the page is editable; the save endpoint will simply ignore disabled fields

---

## 8. Rollout Phases

Ship incrementally so each phase is independently testable.

**Phase 1 — Foundation**
- Add `AccessResolver` class with `userCan()` for a single spec.
- Add `access` key normalization to `ConfigLoader`.
- Add `wp-wireframe/access/resolve` filter.
- Unit tests for resolver (cap, role, array, OR semantics).

**Phase 2 — Config filtering**
- Add `resolveForConfig()` returning `ConfigAccessMap`.
- Strip non-viewable elements before localize.
- Auto-hide empty tabs/sections.
- Add `wp-wireframe/config/for_user` filter.
- Suppress menu registration when access map is empty.

**Phase 3 — REST save + reset protection**
- Intersect save payload with editable map.
- Switch save from overwrite to merge.
- Scope reset to editable fields only.
- Add `wp-wireframe/save/editable_fields`, `wp-wireframe/save/payload`, `wp-wireframe/access/can_reset`, and `wp-wireframe/reset/confirm_message` filters.
- Remove `preserveHiddenFieldValues` (superseded).
- Integration tests: partial-access user cannot wipe other fields via save *or* reset.

**Phase 4 — View-only rendering**
- Add `readonly` flag to filtered config for non-editable visible fields.
- Update built-in field components to honor `readonly`.
- Document the contract for custom field types.

**Phase 5 — Conditional tabs (bonus)**
- Extend tab schema to accept `conditions`.
- Evaluate in `SettingsPage.js` and `Conditions.php`.

**Phase 6 — Docs & example**
- Update README with access section.
- Add a worked example to the field reference plugin (e.g., a section visible to editors, view-only).

---

## Defaults at a glance

Plugin authors should be able to read this table and know exactly what they get out of the box. Every behavior here is the default — all can be overridden via the filters listed in §6.

| Behavior | Default | When does it change? |
|---|---|---|
| Page capability | `manage_options` | Only when author sets `capability` on the page config |
| Menu visibility | Tied to page capability | Switches to `read` floor + per-user filter only when at least one `access` key is declared on the page |
| Tab / section / field access | Inherits from parent (ultimately the page capability) | Author adds an `access` key |
| `access` shorthand `'editor'` | Same value applied to both `view` and `edit` | Use long form `['view' => …, 'edit' => …]` to split |
| `access` value resolution | Match role slug first, then capability | N/A — both formats accepted |
| `access` arrays | Logical OR — any match grants access | N/A |
| `edit` without `view` | Implicitly denied | N/A — `edit` always requires `view` |
| Non-viewable elements | Stripped from config entirely (user doesn't know they exist) | `wp-wireframe/config/for_user` filter |
| Non-editable but viewable fields | Rendered with `readonly: true` (disabled in UI, ignored by server) | N/A |
| Empty tab (all sections stripped) | Auto-hidden | N/A |
| Empty section (all fields stripped) | Auto-hidden | N/A |
| Save endpoint | Merges editable fields into existing values (no overwrite of unseen fields) | `wp-wireframe/save/editable_fields` and `wp-wireframe/save/payload` filters |
| Reset endpoint | Resets only the user's editable fields (admin with full access = effectively reset-all) | `wp-wireframe/access/can_reset` filter |
| Reset eligibility | Any user with at least one editable field on the page may reset | `wp-wireframe/access/can_reset` returns false |
| Reset dialog wording | Neutral: "Reset the settings on this page to their defaults?" — does not leak existence of hidden fields | `wp-wireframe/reset/confirm_message` filter |
| Save button visibility | Hidden when user has zero editable fields | N/A |
| Reset button visibility | Hidden when user has zero editable fields OR `can_reset` is false | N/A |
| REST permission callback (legacy mode) | `current_user_can($page['capability'])` | Page declares an `access` key → switches to RBAC mode |
| REST permission callback (RBAC mode) | `current_user_can('read')` AND has at least one editable field | `wp-wireframe/access/resolve` filter |

These defaults must be reflected in the README and in inline code comments where the relevant filter is applied, so plugin authors don't have to chase the planning doc to understand behavior.

## Resolved decisions

- **Default page capability**: stays `manage_options`. RBAC is strictly opt-in via the `access` key.
- **Menu cap floor when RBAC is active**: `'read'`. Menu suppressed entirely if access map is empty for the user.
- **Server is source of truth**: custom field types may ignore the `readonly` UI hint, but the save endpoint still rejects writes to non-editable fields.
- **Reset semantics**: scoped to editable fields, mirroring save's merge behavior. `edit` implicitly grants reset on the editable subset; the `wp-wireframe/access/can_reset` filter is the escape hatch for audit-trail / forbid-reset use cases.
- **Reset dialog**: neutral wording by default to avoid leaking the existence of hidden fields. Override via `wp-wireframe/reset/confirm_message`.

## Open questions

- Per-page `minCapability` override (e.g., require at least `edit_posts` to reach the page even in RBAC mode)? Defer until a real use case appears.
