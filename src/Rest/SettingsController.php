<?php

declare(strict_types=1);

namespace Wireframe\Rest;

use Wireframe\App;
use Wireframe\Framework\Conditions;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Fields\FieldRegistry;
use Wireframe\Framework\Sanitizer;
use Wireframe\Framework\Validator;
use Wireframe\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API controller for reading, saving, and resetting plugin settings.
 *
 * Multi-tenant: one route set is registered per page, under the owning
 * plugin's prefix namespace:
 *   GET/POST/DELETE /{prefix}/v1/settings/{pageId}
 */
final class SettingsController
{
    /**
     * Register REST routes for every page across every booted plugin.
     */
    public static function register(): void
    {
        foreach (App::pages() as $internalId => $page) {
            $namespace = App::restNamespace($page['prefix']);
            $route     = '/settings/' . $page['page_id'];

            register_rest_route($namespace, $route, [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::getSettings($internalId),
                    'permission_callback' => fn() => self::checkPermission($internalId),
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::saveSettings($r, $internalId),
                    'permission_callback' => fn() => self::checkPermission($internalId),
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => fn(WP_REST_Request $r) => self::resetSettings($internalId),
                    'permission_callback' => fn() => self::checkPermission($internalId),
                ],
            ]);
        }
    }

    /**
     * Check if the current user can manage the given page's settings.
     */
    public static function checkPermission(string $internalId): bool
    {
        $page = App::page($internalId);

        return $page !== null && current_user_can($page['capability']);
    }

    /**
     * Return config and resolved values for the page.
     */
    private static function getSettings(string $internalId): WP_REST_Response
    {
        $page = App::page($internalId);

        return new WP_REST_Response([
            'config' => ConfigLoader::load($page['config']),
            'values' => Settings::resolvedFor($page['option_key'], $page['config']),
        ]);
    }

    /**
     * Validate, sanitize, and persist submitted settings for the page.
     */
    private static function saveSettings(WP_REST_Request $request, string $internalId): WP_REST_Response|WP_Error
    {
        $page    = App::page($internalId);
        $payload = $request->get_json_params();

        if (!is_array($payload)) {
            return new WP_Error(
                'invalid_payload',
                __('Invalid JSON payload.', App::textDomain($page['prefix'])),
                ['status' => 400]
            );
        }

        $optionKey  = $page['option_key'];
        $configSlug = $page['config'];
        $fields     = ConfigLoader::flatFields($configSlug);

        $savedValues   = Settings::allFor($optionKey);
        $mergedValues  = array_merge($savedValues, $payload);
        $visibilityMap = Conditions::visibilityMap($fields, $mergedValues);

        $validationResult = Validator::validate($payload, $fields, $visibilityMap);

        if (!empty($validationResult['errors'])) {
            return new WP_Error(
                'validation_failed',
                __('Validation failed.', App::textDomain($page['prefix'])),
                ['status' => 400, 'errors' => $validationResult['errors']]
            );
        }

        $cleanValues = Sanitizer::sanitize($payload, $fields, $visibilityMap);
        $cleanValues = self::preserveHiddenFieldValues($fields, $visibilityMap, $savedValues, $cleanValues);

        Settings::updateFor($optionKey, $cleanValues);

        do_action(App::hookName($page['prefix'], 'settings_saved'), $cleanValues, $page['page_id']);

        return new WP_REST_Response([
            'success' => true,
            'values'  => Settings::resolvedFor($optionKey, $configSlug),
        ]);
    }

    /**
     * Delete all saved settings for the page.
     */
    private static function resetSettings(string $internalId): WP_REST_Response
    {
        $page = App::page($internalId);

        Settings::resetFor($page['option_key']);

        do_action(App::hookName($page['prefix'], 'settings_reset'), $page['page_id']);

        return new WP_REST_Response([
            'success' => true,
            'values'  => Settings::resolvedFor($page['option_key'], $page['config']),
        ]);
    }

    /**
     * Copy values for conditionally-hidden fields from the existing saved state.
     */
    private static function preserveHiddenFieldValues(
        array $fields,
        array $visibilityMap,
        array $savedValues,
        array $cleanValues,
    ): array {
        $registry = FieldRegistry::instance();

        foreach ($fields as $fieldId => $fieldConfig) {
            if (str_contains($fieldId, '.')) {
                continue;
            }

            $isVisible     = $visibilityMap[$fieldId] ?? true;
            $hasSavedValue = array_key_exists($fieldId, $savedValues);

            if (!$isVisible && $hasSavedValue) {
                $type    = $fieldConfig['type'] ?? 'text';
                $handler = $registry->get($type);
                $args    = $fieldConfig['args'] ?? [];

                if (!$handler::isStateless()) {
                    $cleanValues[$fieldId] = $handler::sanitize($savedValues[$fieldId], $args);
                }
            }
        }

        return $cleanValues;
    }
}
