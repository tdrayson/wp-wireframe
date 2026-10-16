<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_Post;
use WP_Screen;
use Wireframe\Admin\AdminPage;
use Wireframe\App;
use Wireframe\Framework\Access\AccessResolver;
use Wireframe\Framework\ConfigLoader;

/**
 * Replaces the post edit screen with the React field editor for post types that have a `content` group.
 */
final class EditScreen
{
    private const HANDLE = 'wp-wireframe-post-fields';

    /**
     * Filter `replace_editor`: render the field editor for post types this copy owns.
     *
     * @param bool    $replace Whether another filter already replaced the editor.
     * @param WP_Post $post    Post being edited.
     * @return bool
     */
    public static function maybeReplace(bool $replace, WP_Post $post): bool
    {
        $assetFile = App::assetsDir() . 'post-fields.asset.php';

        if (self::contentGroup($post->post_type) === null || !file_exists($assetFile)) {
            return $replace;
        }

        // WP_Screen::get() asks the same filter while building the screen; answer without rendering.
        if (get_current_screen() === null) {
            return true;
        }

        if (!wp_check_post_lock($post->ID)) {
            wp_set_post_lock($post->ID);
        }

        self::enqueue(require $assetFile, $post);

        require ABSPATH . 'wp-admin/admin-header.php';

        echo '<div id="wireframe-post-fields" class="wireframe-post-fields"></div>';

        return true;
    }

    /**
     * Whether a screen is a post edit screen this copy takes over.
     *
     * @param WP_Screen $screen Current screen.
     * @return bool
     */
    public static function isTakenOver(WP_Screen $screen): bool
    {
        return $screen->base === 'post' && self::contentGroup($screen->post_type) !== null;
    }

    /**
     * Build the `window.wireframePostFields` payload for a post.
     *
     * @param WP_Post $post Post being edited.
     * @return array
     */
    public static function data(WP_Post $post): array
    {
        $postType = $post->post_type;
        $type     = get_post_type_object($postType);

        return [
            'config'     => self::configFor($postType),
            'postId'     => $post->ID,
            'postType'   => $postType,
            'restBase'   => ($type->rest_base ?? '') ?: $postType,
            'isNew'      => $post->post_status === 'auto-draft',
            'canPublish' => current_user_can($type->cap->publish_posts ?? 'publish_posts'),
            'lock'       => self::lock($post),
            'listUrl'    => admin_url($postType === 'post' ? 'edit.php' : 'edit.php?post_type=' . $postType),
            'supports'   => [
                'title'     => post_type_supports($postType, 'title'),
                'thumbnail' => post_type_supports($postType, 'thumbnail'),
                'author'    => post_type_supports($postType, 'author'),
            ],
            'taxonomies' => self::taxonomies($postType),
        ];
    }

    /**
     * Enqueue the bundle, its data and the WordPress editors the field types use.
     *
     * @param array{dependencies: string[], version: string} $asset Build asset manifest.
     * @param WP_Post                                        $post  Post being edited.
     */
    private static function enqueue(array $asset, WP_Post $post): void
    {
        $url = App::assetsUrl();

        wp_enqueue_script(self::HANDLE, $url . 'post-fields.js', [...$asset['dependencies'], 'heartbeat'], $asset['version'], true);
        wp_set_script_translations(self::HANDLE, 'wp-wireframe', App::packageDir() . 'languages');

        if (file_exists(App::assetsDir() . 'post-fields.css')) {
            wp_enqueue_style(self::HANDLE, $url . 'post-fields.css', ['wp-components'], $asset['version']);
        }

        AdminPage::enqueueWordPressEditors();

        // Not wp_localize_script(): it stringifies top-level scalars, so `false` arrives as "".
        wp_add_inline_script(self::HANDLE, 'window.wireframePostFields = ' . wp_json_encode(self::data($post)) . ';', 'before');
    }

    /**
     * Get a post type's field config with everything the current user may not see removed.
     */
    private static function configFor(string $postType): array
    {
        $group = self::contentGroup($postType);

        if ($group === null) {
            return [];
        }

        $config   = ConfigLoader::load($group['config']);
        $resolver = new AccessResolver(get_post_type_object($postType)?->cap->edit_posts ?? 'edit_posts');

        return $resolver->filterConfig($config, $resolver->resolveForConfig($config));
    }

    /**
     * Get the post type's `content` group, the only one this takeover renders.
     */
    private static function contentGroup(string $postType): ?array
    {
        foreach (FieldGroups::groupsFor($postType) as $group) {
            if ($group['display']['position'] === 'content') {
                return $group;
            }
        }

        return null;
    }

    /**
     * Get who holds the post lock, the URL that takes it over, and the current lock string.
     *
     * @return array{holder: ?array{id: int, name: string, avatar: string}, takeOverUrl: string, value: string}
     */
    private static function lock(WP_Post $post): array
    {
        $holderId = wp_check_post_lock($post->ID);
        $holder   = $holderId ? get_userdata((int) $holderId) : false;
        $editUrl  = get_edit_post_link($post->ID, 'raw') ?: admin_url('post.php?post=' . $post->ID . '&action=edit');

        return [
            'holder'      => $holder ? [
                'id'     => $holder->ID,
                'name'   => $holder->display_name,
                'avatar' => (string) get_avatar_url($holder->ID, ['size' => 64]),
            ] : null,
            'takeOverUrl' => wp_specialchars_decode(
                wp_nonce_url(add_query_arg('get-post-lock', '1', $editUrl), 'lock-post_' . $post->ID)
            ),
            'value'       => (string) get_post_meta($post->ID, '_edit_lock', true),
        ];
    }

    /**
     * Get the taxonomies of a post type the current user can assign terms from.
     *
     * @return list<array{slug: string, restBase: string, hierarchical: bool, label: string}>
     */
    private static function taxonomies(string $postType): array
    {
        $taxonomies = [];

        foreach (get_object_taxonomies($postType, 'objects') as $taxonomy) {
            if (!$taxonomy->show_ui || !$taxonomy->show_in_rest || !current_user_can($taxonomy->cap->assign_terms)) {
                continue;
            }

            $taxonomies[] = [
                'slug'         => $taxonomy->name,
                'restBase'     => ($taxonomy->rest_base ?? '') ?: $taxonomy->name,
                'hierarchical' => (bool) $taxonomy->hierarchical,
                'label'        => $taxonomy->label,
            ];
        }

        return $taxonomies;
    }
}
