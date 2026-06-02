<?php

declare(strict_types=1);

namespace Wireframe\Framework\Access;

/**
 * Per-user snapshot of what a single page config grants.
 *
 * Built by AccessResolver::resolveForConfig() once per request. Carries
 * everything the menu registration, config-localize, and REST endpoints
 * need to enforce role-based access consistently.
 *
 *   - viewable: nested map of tabId → sectionId → fieldId[] that survives filtering.
 *   - editable: flat list of field IDs the current user may write.
 *   - canReset: whether the current user may invoke the reset endpoint.
 *   - mode:     'legacy' (no access keys anywhere → behave like today) or 'rbac'.
 */
final class ConfigAccessMap
{
    /**
     * @param array<string, array<string, list<string>>> $viewable Tab → section → field IDs.
     * @param list<string>                                $editable Flat list of editable field IDs.
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $viewable,
        public readonly array $editable,
        public readonly bool $canReset,
    ) {
    }

    public function isLegacy(): bool
    {
        return $this->mode === 'legacy';
    }

    public function isRbac(): bool
    {
        return $this->mode === 'rbac';
    }

    /**
     * Whether the user has access to anything at all on this page.
     *
     * Used by AdminPage to decide whether to register the menu item in
     * RBAC mode — if the access map is empty, the page is hidden entirely.
     */
    public function hasAnyAccess(): bool
    {
        return !empty($this->viewable);
    }

    public function canEdit(string $fieldId): bool
    {
        return in_array($fieldId, $this->editable, true);
    }
}
