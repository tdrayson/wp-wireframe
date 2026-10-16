<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_Post;
use WP_User;
use Wireframe\Admin\AdminPage;
use Wireframe\App;
use Wireframe\Meta;

/**
 * Renders field groups on the classic edit screen: boxes, the content area, assets and data, and save error notices.
 */
final class ClassicEditor
{
    public const NONCE_ACTION = 'wireframe_classic_save';

    public const NONCE_FIELD = '_wireframe_nonce';

    public const INPUT = 'wireframe_fields';

    private const HANDLE = 'wp-wireframe-post-fields-classic';

    private const ASSET = 'post-fields-classic';

    private const BOX_CONTEXTS = ['meta_box' => 'normal', 'sidebar' => 'side'];

    /** @var array<int, array> Errors of the last failed save, by post id, read once per request. */
    private static array $errors = [];

    /**
     * Hook into the classic edit screen.
     */
    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'registerBoxes'], 10, 2);
        add_action('edit_form_after_title', [self::class, 'renderContent']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_notices', [self::class, 'notice']);
    }

    /**
     * Build the classic payload: the block editor's, plus each group's values and the last failed save's errors.
     *
     * @param WP_Post      $post Post being edited.
     * @param WP_User|null $user User to resolve for; defaults to the current user.
     * @return array{postId: int, postType: string, supportsTitle: bool, debug: bool, groups: list<array>, isNew: bool, errors: array}
     */
    public static function data(WP_Post $post, ?WP_User $user = null): array
    {
        $data   = EditorIntegration::data($post, $user);
        $values = Meta::resolved($post->ID);

        $data['groups'] = array_map(
            static fn (array $group): array => $group + ['values' => self::groupValues($group['config'], $values)],
            $data['groups']
        );
        $data['isNew']  = $post->post_status === 'auto-draft';
        $data['errors'] = self::errors($post->ID);

        return $data;
    }

    /**
     * Register a box per applicable `meta_box` (normal) and `sidebar` (side) group on a classic screen.
     *
     * @param string $postType Post type, or `comment`/`link` on those screens.
     * @param object $post     Object being edited.
     */
    public static function registerBoxes(string $postType, object $post): void
    {
        if (!self::isClassic($post)) {
            return;
        }

        foreach (EditorIntegration::data($post)['groups'] as $group) {
            $context = self::BOX_CONTEXTS[$group['position']] ?? null;

            if ($context !== null) {
                EditorIntegration::addBox($group, $postType, $context, [self::class, 'renderBox']);
            }
        }

        add_filter("get_user_option_closedpostboxes_{$postType}", [self::class, 'seedClosedBoxes'], 10, 2);
    }

    /**
     * Print a box's hidden input and mount point.
     *
     * @param WP_Post $post Post being edited.
     * @param array   $box  Box registration; `args.group` is the group id.
     */
    public static function renderBox(WP_Post $post, array $box): void
    {
        self::printMount((string) ($box['args']['group'] ?? ''));
    }

    /**
     * Action `edit_form_after_title`: print the save nonce, and the `content` group in place of the content editor.
     *
     * @param WP_Post $post Post being edited.
     */
    public static function renderContent(WP_Post $post): void
    {
        if (!self::isClassic($post)) {
            return;
        }

        $groups = EditorIntegration::data($post)['groups'];

        if ($groups === []) {
            return;
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD, false);

        foreach ($groups as $group) {
            if ($group['position'] !== 'content') {
                continue;
            }

            printf('<div id="wireframe-content-%s" class="wireframe-classic-content">', esc_attr($group['id']));
            self::printMount($group['id']);
            echo '</div>';
        }
    }

    /**
     * Filter `get_user_option_closedpostboxes_{$postType}`: start `collapsed` boxes, sidebar ones included, closed.
     *
     * @param mixed  $result Saved closed box ids, or false when the user has none.
     * @param string $option Option name, `closedpostboxes_{$postType}`.
     * @return mixed
     */
    public static function seedClosedBoxes(mixed $result, string $option): mixed
    {
        return EditorIntegration::closedBoxes($result, $option, array_keys(self::BOX_CONTEXTS));
    }

    /**
     * Enqueue the classic bundle, its data and the WordPress editors the field types use, on a classic edit screen with groups.
     *
     * @param string $hook Admin page hook suffix.
     */
    public static function enqueue(string $hook): void
    {
        $post      = get_post();
        $assetFile = App::assetsDir() . self::ASSET . '.asset.php';

        if (!in_array($hook, ['post.php', 'post-new.php'], true) || !self::isClassic($post) || !file_exists($assetFile)) {
            return;
        }

        $data = self::data($post);

        if ($data['groups'] === []) {
            return;
        }

        $asset = require $assetFile;
        $url   = App::assetsUrl();

        wp_enqueue_script(self::HANDLE, $url . self::ASSET . '.js', $asset['dependencies'], $asset['version'], true);
        wp_set_script_translations(self::HANDLE, 'wp-wireframe', App::packageDir() . 'languages');

        if (file_exists(App::assetsDir() . self::ASSET . '.css')) {
            wp_enqueue_style(self::HANDLE, $url . self::ASSET . '.css', ['wp-components'], $asset['version']);
        }

        // Core only binds the media frame to the post when the type supports an editor or thumbnail.
        wp_enqueue_media(['post' => $post->ID]);
        AdminPage::enqueueWordPressEditors();

        wp_add_inline_script(self::HANDLE, 'window.wireframePostFields = ' . wp_json_encode($data) . ';', 'before');
    }

    /**
     * Action `admin_notices`: report the last failed save on its classic edit screen.
     */
    public static function notice(): void
    {
        $post = get_post();

        if (get_current_screen()?->base !== 'post' || !self::isClassic($post) || self::errors($post->ID) === []) {
            return;
        }

        wp_admin_notice(esc_html(SaveValidator::message($post->post_type, $post->ID)), ['type' => 'error']);
    }

    /**
     * Whether an object is a post edited on the classic screen.
     */
    private static function isClassic(mixed $post): bool
    {
        return $post instanceof WP_Post && !use_block_editor_for_post($post);
    }

    /**
     * Print a group's hidden values input, left empty until the script fills it, and its mount point.
     */
    private static function printMount(string $groupId): void
    {
        printf('<input type="hidden" name="%s" value="" />', esc_attr(self::INPUT . "[{$groupId}]"));
        echo '<div class="wireframe-group"></div>';
    }

    /**
     * Get the values of the top-level fields left in an access-filtered group config.
     *
     * @param array                $config Group config from data().
     * @param array<string, mixed> $values Post values by meta key.
     * @return array<string, mixed>
     */
    private static function groupValues(array $config, array $values): array
    {
        $ids = [];

        foreach ($config['tabs'] ?? [] as $tab) {
            foreach ($tab['sections'] ?? [] as $section) {
                $ids = [...$ids, ...array_column($section['fields'] ?? [], 'id')];
            }
        }

        return array_intersect_key($values, array_flip($ids));
    }

    /**
     * Get, and clear, the errors the current user's last failed save of a post left behind.
     */
    private static function errors(int $postId): array
    {
        if (!array_key_exists($postId, self::$errors)) {
            $key    = ClassicSave::errorsKey(get_current_user_id(), $postId);
            $stored = get_transient($key);

            delete_transient($key);
            self::$errors[$postId] = is_array($stored['errors'] ?? null) ? $stored['errors'] : [];
        }

        return self::$errors[$postId];
    }
}
