<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_Block_Editor_Context;
use WP_Post;
use WP_User;
use Wireframe\Admin\AdminPage;
use Wireframe\App;
use Wireframe\Framework\ConfigLoader;
use Wireframe\Framework\Populate;
use Wireframe\Meta;

/**
 * Hooks field groups into the block editor: assets and data, `content` template locking, and meta box containers.
 */
final class EditorIntegration
{
    private const HANDLE = 'wp-wireframe-post-fields';

    private const BOX_PREFIX = 'wireframe-group-';

    private const HIDDEN_BOX_CLASS = 'wireframe-group-inapplicable';

    /** @var array<string, array> Payloads by post and user ID, as the classic editor asks once per box. */
    private static array $data = [];

    /**
     * Build the `window.wireframePostFields` payload: the groups whose static params match, with access-filtered, populated configs.
     *
     * `context` holds the location params JS can't read from the editor; `taxonomies` the REST bases live terms come from.
     *
     * @param WP_Post      $post Post being edited.
     * @param WP_User|null $user User to resolve for; defaults to the current user.
     * @return array{postId: int, postType: string, supportsTitle: bool, debug: bool, context: array, taxonomies: list<array>, groups: list<array>}
     */
    public static function data(WP_Post $post, ?WP_User $user = null): array
    {
        $user ??= wp_get_current_user();
        $key    = "{$post->ID}:{$user->ID}";

        return self::$data[$key] ??= self::build($post, $user);
    }

    /**
     * Get the groups from data() whose location applies to the stored post.
     *
     * @param WP_Post      $post Post being edited.
     * @param WP_User|null $user User to resolve for; defaults to the current user.
     * @return list<array> Group entries from data().
     */
    public static function applicable(WP_Post $post, ?WP_User $user = null): array
    {
        $user ??= wp_get_current_user();
        $context = Location::context($post->ID, $user->exists() ? $user : null);

        return array_values(array_filter(
            self::data($post, $user)['groups'],
            static fn (array $group): bool => Location::matches($group, $context)
        ));
    }

    /**
     * Forget built payloads, for when a post's groups, values or the user's access change within a request.
     */
    public static function flush(): void
    {
        self::$data = [];
    }

    /**
     * Build the payload for a post and user.
     */
    private static function build(WP_Post $post, WP_User $user): array
    {
        $groups = [];
        $values = Meta::resolved($post->ID);

        foreach (FieldGroups::groupsFor($post->post_type) as $group) {
            $entry = self::groupData($group, $post, $user, $values);

            if ($entry !== null) {
                $groups[] = $entry;
            }
        }

        $context    = Location::context($post->ID, $user->exists() ? $user : null);
        $taxonomies = Location::restTaxonomies($post->post_type);

        return [
            'postId'        => $post->ID,
            'postType'      => $post->post_type,
            'supportsTitle' => post_type_supports($post->post_type, 'title'),
            'debug'         => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG,
            'context'       => array_diff_key($context, array_flip(Location::LIVE_PARAMS)),
            'taxonomies'    => array_map(static fn (string $slug, string $base): array => ['slug' => $slug, 'restBase' => $base], array_keys($taxonomies), $taxonomies),
            'groups'        => $groups,
        ];
    }

    /**
     * Enqueue the bundle, its data and the WordPress editors the field types use, when the edited post has groups.
     */
    public static function enqueue(): void
    {
        $post      = get_post();
        $assetFile = App::assetsDir() . 'post-fields.asset.php';

        if (!$post instanceof WP_Post || get_current_screen()?->base !== 'post' || !file_exists($assetFile)) {
            return;
        }

        $data = self::data($post);

        if ($data['groups'] === []) {
            return;
        }

        $asset = require $assetFile;
        $url   = App::assetsUrl();

        wp_enqueue_script(self::HANDLE, $url . 'post-fields.js', $asset['dependencies'], $asset['version'], true);
        wp_set_script_translations(self::HANDLE, 'wp-wireframe', App::packageDir() . 'languages');

        if (file_exists(App::assetsDir() . 'post-fields.css')) {
            wp_enqueue_style(self::HANDLE, $url . 'post-fields.css', ['wp-components'], $asset['version']);
        }

        AdminPage::enqueueWordPressEditors();

        // Not wp_localize_script(): it stringifies top-level scalars, so `false` arrives as "".
        wp_add_inline_script(self::HANDLE, 'window.wireframePostFields = ' . wp_json_encode($data) . ';', 'before');
    }

    /**
     * Register an empty container box per `meta_box` group, hidden while its live rules don't match; React renders into it.
     *
     * @param string $postType Post type, or `comment`/`link` on those screens.
     * @param object $post     Object being edited; only posts on the block editor get boxes here.
     */
    public static function registerMetaBoxes(string $postType, object $post): void
    {
        if (!$post instanceof WP_Post || !use_block_editor_for_post($post)) {
            return;
        }

        $applicable = array_column(self::applicable($post), 'id');

        foreach (self::data($post)['groups'] as $group) {
            if ($group['position'] !== 'meta_box') {
                continue;
            }

            self::addBox($group, $postType, 'normal', [self::class, 'renderMetaBox']);

            $hook = 'postbox_classes_' . $postType . '_' . self::BOX_PREFIX . $group['id'];
            remove_filter($hook, [self::class, 'hideBox']);

            if (!in_array($group['id'], $applicable, true)) {
                add_filter($hook, [self::class, 'hideBox']);
            }
        }

        add_filter("get_user_option_closedpostboxes_{$postType}", [self::class, 'seedClosedBoxes'], 10, 2);
    }

    /**
     * Register a group's container box, titled and prioritised from its display settings.
     *
     * @param array    $group    Group entry from data().
     * @param string   $postType Post type slug.
     * @param string   $context  Box context: `normal` or `side`.
     * @param callable $callback Prints the box's mount point; receives the group id as `$box['args']['group']`.
     */
    public static function addBox(array $group, string $postType, string $context, callable $callback): void
    {
        add_meta_box(self::BOX_PREFIX . $group['id'], $group['title'], $callback, $postType, $context, self::priority($group['order']), ['group' => $group['id']]);
    }

    /**
     * Filter `postbox_classes_{$screen}_{$box}`: hide a group's box until JS finds its live rules met.
     *
     * @param array $classes Box classes.
     * @return array
     */
    public static function hideBox(array $classes): array
    {
        return [...$classes, self::HIDDEN_BOX_CLASS];
    }

    /**
     * Print a meta box's mount point.
     */
    public static function renderMetaBox(): void
    {
        echo '<div class="wireframe-group"></div>';
    }

    /**
     * Filter `get_user_option_closedpostboxes_{$postType}`: start `collapsed` boxes closed until the user saves their own state.
     *
     * @param mixed  $result Saved closed box ids, or false when the user has none.
     * @param string $option Option name, `closedpostboxes_{$postType}`.
     * @return mixed
     */
    public static function seedClosedBoxes(mixed $result, string $option): mixed
    {
        return self::closedBoxes($result, $option, ['meta_box']);
    }

    /**
     * Seed a user's closed boxes with the `collapsed` groups in the given positions, unless they saved their own state.
     *
     * @param mixed        $result    Saved closed box ids, or false when the user has none.
     * @param string       $option    Option name, `closedpostboxes_{$postType}`.
     * @param list<string> $positions Positions rendered as boxes.
     * @return mixed
     */
    public static function closedBoxes(mixed $result, string $option, array $positions): mixed
    {
        if ($result !== false) {
            return $result;
        }

        $closed = [];

        foreach (FieldGroups::groupsFor(substr($option, strlen('closedpostboxes_'))) as $group) {
            if (in_array($group['display']['position'], $positions, true) && $group['display']['collapsed']) {
                $closed[] = self::BOX_PREFIX . $group['id'];
            }
        }

        return $closed === [] ? $result : $closed;
    }

    /**
     * Give a post type the block editor with an empty, fully locked template, so the canvas has no inserter.
     *
     * @param string $postType Post type with a `content` group.
     */
    public static function lockTemplate(string $postType): void
    {
        global $wp_post_types;

        add_post_type_support($postType, 'editor');

        if (!isset($wp_post_types[$postType])) {
            return;
        }

        $wp_post_types[$postType]->template      = [];
        $wp_post_types[$postType]->template_lock = 'all';
    }

    /**
     * Filter `register_post_type_args`: keep the locked template when a `content` post type is registered again.
     *
     * @param array  $args     Post type args.
     * @param string $postType Post type slug.
     * @return array
     */
    public static function filterPostTypeArgs(array $args, string $postType): array
    {
        if (!isset($GLOBALS['wireframe_field_group_owners'][$postType])) {
            return $args;
        }

        $args['template']      = [];
        $args['template_lock'] = 'all';

        if (is_array($args['supports'] ?? null)) {
            $args['supports'] = array_values(array_unique([...$args['supports'], 'title', 'editor']));
        }

        return $args;
    }

    /**
     * Filter `block_editor_settings_all`: lock the canvas when the post's `content` group applies to the stored post; JS takes over live.
     *
     * Core only passes `template_lock` on when the template is non-empty, so an empty template needs this.
     *
     * @param array                   $settings Block editor settings.
     * @param WP_Block_Editor_Context $context  Editor context.
     * @return array
     */
    public static function filterEditorSettings(array $settings, WP_Block_Editor_Context $context): array
    {
        if (!$context->post instanceof WP_Post || !isset($GLOBALS['wireframe_field_group_owners'][$context->post->post_type])) {
            return $settings;
        }

        if (!in_array('content', array_column(self::applicable($context->post), 'position'), true)) {
            return $settings;
        }

        $settings['template']     = [];
        $settings['templateLock'] = 'all';

        return $settings;
    }

    /**
     * Get a group's payload entry, or null when its static params don't match or the user can see none of its fields.
     */
    private static function groupData(array $group, WP_Post $post, WP_User $user, array $values): ?array
    {
        if (!Location::staticMatch($group, $post->ID, $user->exists() ? $user : null)) {
            return null;
        }

        $config = self::configFor($group, $post->post_type, $user);

        if (!self::hasFields($config)) {
            return null;
        }

        return [
            'id'        => $group['id'],
            'prefix'    => $group['prefix'],
            'title'     => $group['title'],
            'position'  => $group['display']['position'],
            'collapsed' => $group['display']['collapsed'],
            'order'     => $group['display']['order'],
            'location'  => $group['location'],
            'config'    => Populate::resolveConfig($config, $values),
        ];
    }

    /**
     * Get a group's config with everything the user may not see removed.
     */
    private static function configFor(array $group, string $postType, WP_User $user): array
    {
        $config = ConfigLoader::load($group['config']);

        return MetaRegistrar::resolver($postType)->filterConfig($config, MetaRegistrar::accessMap($postType, $group, $user));
    }

    /**
     * Whether a normalised config has at least one field left.
     */
    private static function hasFields(array $config): bool
    {
        foreach ($config['tabs'] ?? [] as $tab) {
            foreach ($tab['sections'] ?? [] as $section) {
                if (!empty($section['fields'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Map a group's `order` to a meta box priority.
     */
    private static function priority(int $order): string
    {
        return match (true) {
            $order <= 5  => 'high',
            $order >= 20 => 'low',
            default      => 'default',
        };
    }
}
