<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use stdClass;
use WP_Error;
use WP_REST_Request;
use WP_User;
use Wireframe\App;
use Wireframe\Framework\Conditions;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Validator;
use Wireframe\Meta;

/**
 * Validates post field meta on REST saves, the way settings pages validate their payload.
 */
final class SaveValidator
{
    /** Statuses a save must reach before `required` is enforced. */
    private const ENFORCED_STATUSES = ['publish', 'future'];

    /**
     * Refuse a REST save whose stored meta, merged with the request's, fails validation.
     *
     * @param stdClass|WP_Error $prepared Post prepared for the database, or an earlier filter's error.
     * @param WP_REST_Request   $request  Save request.
     * @return stdClass|WP_Error
     */
    public static function check(stdClass|WP_Error $prepared, WP_REST_Request $request): stdClass|WP_Error
    {
        if ($prepared instanceof WP_Error) {
            return $prepared;
        }

        // Core's autosave controller uses this filter's result without checking for an error.
        if (self::isAutosave($request)) {
            return self::withoutPublishing($prepared);
        }

        $postType = (string) ($prepared->post_type ?? '');
        $postId   = (int) ($prepared->ID ?? 0);
        $status   = (string) ($request['status'] ?? ($postId > 0 ? get_post_status($postId) : 'draft'));
        $incoming = is_array($request['meta'] ?? null) ? $request['meta'] : [];
        $errors   = self::validate($postId, $postType, $incoming, $status);

        if ($errors === []) {
            return $prepared;
        }

        return new WP_Error(
            'validation_failed',
            self::message($postType, $postId),
            ['status' => 400, 'errors' => $errors]
        );
    }

    /**
     * Validate a post's stored values merged with incoming ones, for every applicable field the user may edit.
     *
     * @param int                  $postId       Post ID; 0 for one not yet created.
     * @param string               $postType     Post type slug.
     * @param array<string, mixed> $incoming     Values being saved, by meta key.
     * @param string               $targetStatus Status the save leaves the post in; `required` applies to publish and future only.
     * @param WP_User|null         $user         User saving; defaults to the current user.
     * @return array<string, mixed> Errors by field id, in the settings `data.errors` shape; empty when valid.
     */
    public static function validate(int $postId, string $postType, array $incoming, string $targetStatus, ?WP_User $user = null): array
    {
        $user ??= wp_get_current_user();
        $groups = self::applicableGroups($postType, $postId, $user);
        $fields = self::editableFields($groups, $postType, $user);

        if ($fields === []) {
            return [];
        }

        if (!self::enforces($targetStatus)) {
            $fields = self::withoutRequired($fields);
        }

        $values = self::mergedValues($postId, $postType, $incoming);

        return Validator::validate($values, $fields, self::visibility($groups, $values))['errors'];
    }

    /**
     * Whether a post status enforces `required`.
     *
     * @param string $status Post status.
     * @return bool
     */
    public static function enforces(string $status): bool
    {
        return in_array($status, self::ENFORCED_STATUSES, true);
    }

    /**
     * Get the failed-validation message, in the text domain of the first group that applies to the post.
     *
     * @param string $postType Post type slug.
     * @param int    $postId   Post ID; 0 for one not yet created.
     * @return string
     */
    public static function message(string $postType, int $postId): string
    {
        $groups = self::applicableGroups($postType, $postId, wp_get_current_user());

        return __('Some fields need attention.', App::textDomain($groups[0]['prefix'] ?? ''));
    }

    /**
     * Strip `required`, and any `required` rule in `validation`, from fields and their repeater subfields.
     *
     * @param array<string, array> $flatFields Field configs by id.
     * @return array<string, array>
     */
    public static function withoutRequired(array $flatFields): array
    {
        return array_map([self::class, 'fieldWithoutRequired'], $flatFields);
    }

    /**
     * Whether a request saves an autosave.
     */
    private static function isAutosave(WP_REST_Request $request): bool
    {
        return str_ends_with($request->get_route(), '/autosaves');
    }

    /**
     * Drop a publishing status from an autosave, which core would otherwise apply to an author's own draft unvalidated.
     */
    private static function withoutPublishing(stdClass $prepared): stdClass
    {
        if (!in_array($prepared->post_status ?? null, self::ENFORCED_STATUSES, true)) {
            return $prepared;
        }

        $kept = clone $prepared;
        unset($kept->post_status);

        return $kept;
    }

    /**
     * Get the post type's groups whose location applies to this post and user.
     *
     * @return list<array>
     */
    private static function applicableGroups(string $postType, int $postId, WP_User $user): array
    {
        $current = $user->exists() ? $user : null;

        return array_values(array_filter(
            FieldGroups::groupsFor($postType),
            static fn (array $group): bool => Location::applies($group, $postId, $current, $postType)
        ));
    }

    /**
     * Get the top-level fields of the given groups that the user may edit, keyed by id.
     *
     * @return array<string, array>
     */
    private static function editableFields(array $groups, string $postType, WP_User $user): array
    {
        $fields = [];

        foreach ($groups as $group) {
            $map = MetaRegistrar::accessMap($postType, $group, $user);

            foreach (ConfigLoader::flatFields($group['config']) as $key => $field) {
                $key = (string) $key;

                if (!str_contains($key, '.') && $map->canEdit($key)) {
                    $fields[$key] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * Get the post's values as the editor shows them (stored, else default), overlaid with the incoming ones.
     *
     * @return array<string, mixed>
     */
    private static function mergedValues(int $postId, string $postType, array $incoming): array
    {
        $saved = $postId > 0 ? Meta::resolved($postId) : self::defaults($postType);

        return array_replace($saved, $incoming);
    }

    /**
     * Get the config defaults of a post type's top-level fields, for a post not yet created.
     *
     * @return array<string, mixed>
     */
    private static function defaults(string $postType): array
    {
        $defaults = [];

        foreach (FieldGroups::flatFields($postType) as $key => $field) {
            if (!str_contains((string) $key, '.') && array_key_exists('default', $field)) {
                $defaults[$key] = $field['default'];
            }
        }

        return $defaults;
    }

    /**
     * Build the visibility map of the given groups, honouring field, section and tab conditions.
     *
     * @return array<string, bool>
     */
    private static function visibility(array $groups, array $values): array
    {
        $map = [];

        foreach ($groups as $group) {
            $fieldMap = Conditions::visibilityMap(ConfigLoader::flatFields($group['config']), $values);
            $map     += Conditions::withContainers($fieldMap, ConfigLoader::load($group['config']), $values);
        }

        return $map;
    }

    /**
     * Strip `required` from one field config and its repeater subfields.
     */
    private static function fieldWithoutRequired(array $field): array
    {
        unset($field['required']);

        if (isset($field['validation'])) {
            $field['validation'] = self::withoutRequiredRule($field['validation']);
        }

        if (is_array($field['args']['subfields'] ?? null)) {
            $field['args']['subfields'] = array_map([self::class, 'fieldWithoutRequired'], $field['args']['subfields']);
        }

        return $field;
    }

    /**
     * Drop the `required` rule from a `validation` string or list, keeping its form.
     */
    private static function withoutRequiredRule(mixed $rules): mixed
    {
        $isRequired = static fn (mixed $rule): bool => is_string($rule) && trim($rule) === 'required';

        if (is_array($rules)) {
            return array_values(array_filter($rules, static fn (mixed $rule): bool => !$isRequired($rule)));
        }

        if (!is_string($rules)) {
            return $rules;
        }

        return implode('|', array_filter(explode('|', $rules), static fn (string $rule): bool => !$isRequired($rule)));
    }
}
