<?php

declare(strict_types=1);

namespace Wireframe\PostFields;

use WP_Post;
use WP_Taxonomy;
use WP_Term;
use Wireframe\Meta;

/**
 * Saves field groups posted from the classic edit form, with the REST write gate and validation.
 */
final class ClassicSave
{
    private const ERRORS_TTL = 60;

    /** Core's "Post draft updated." edit screen message. */
    private const DRAFT_UPDATED = 10;

    /** @var array<int, string> Status requested for posts kept as drafts by guardStatus(), by post id. */
    private static array $demoted = [];

    /**
     * Hook the classic save path.
     */
    public static function register(): void
    {
        add_filter('wp_insert_post_data', [self::class, 'guardStatus'], 10, 2);
        add_action('save_post', [self::class, 'handle'], 10, 2);
        add_filter('redirect_post_location', [self::class, 'redirectLocation'], 10, 2);
    }

    /**
     * Filter `wp_insert_post_data`: keep a post as a draft when publishing it would store invalid field values.
     *
     * A post that is already published or scheduled keeps its status; only its invalid values are refused.
     *
     * @param array $data    Post data about to be stored.
     * @param array $postarr Raw, slashed post array; on the classic screen it carries the posted form.
     * @return array
     */
    public static function guardStatus(array $data, array $postarr): array
    {
        $postId = (int) ($postarr['ID'] ?? 0);
        $status = (string) ($data['post_status'] ?? '');

        if (!SaveValidator::enforces($status) || !self::isClassicSave($postId, $postarr)) {
            return $data;
        }

        if (SaveValidator::enforces((string) get_post_status($postId))) {
            return $data;
        }

        $postType = (string) ($data['post_type'] ?? '');
        $live     = self::submittedLive($postId, $postType, $data, $postarr);
        $incoming = self::incoming($postId, $postType, $postarr, $live);

        if (SaveValidator::validate($postId, $postType, $incoming, $status, null, $live) === []) {
            return $data;
        }

        self::$demoted[$postId] = $status;

        return self::asDraft($data, $postarr);
    }

    /**
     * Action `save_post`: write the posted field values that pass the write gate and validation; report the rest.
     *
     * @param int     $postId Saved post ID.
     * @param WP_Post $post   Saved post.
     */
    public static function handle(int $postId, WP_Post $post): void
    {
        $request = $_POST;

        if (wp_is_post_revision($postId) || !self::isClassicSave($postId, $request)) {
            return;
        }

        $incoming = self::incoming($postId, $post->post_type, $request);
        $status = self::$demoted[$postId] ?? $post->post_status;
        $errors = SaveValidator::validate($postId, $post->post_type, $incoming, $status);

        foreach (array_diff_key($incoming, $errors) as $key => $value) {
            self::write($postId, (string) $key, $value);
        }

        self::storeFailure($postId, $errors, array_intersect_key($incoming, $errors));
    }

    /**
     * Filter `redirect_post_location`: say "draft updated" rather than "published" for a post guardStatus() kept as a draft.
     *
     * @param string $location Redirect URL.
     * @param int    $postId   Saved post ID.
     * @return string
     */
    public static function redirectLocation(string $location, int $postId): string
    {
        if (!isset(self::$demoted[$postId])) {
            return $location;
        }

        return add_query_arg('message', self::DRAFT_UPDATED, $location);
    }

    /**
     * Get the transient key holding a user's last failed save of a post.
     *
     * @param int $userId User ID.
     * @param int $postId Post ID.
     * @return string
     */
    public static function errorsKey(int $userId, int $postId): string
    {
        return "wireframe_errors_{$userId}_{$postId}";
    }

    /**
     * Turn post data bound for publishing back into a draft; an unedited date floats again, as a draft's does.
     */
    private static function asDraft(array $data, array $postarr): array
    {
        $data['post_status'] = 'draft';

        if (empty($postarr['edit_date'])) {
            $data['post_date_gmt'] = '0000-00-00 00:00:00';
        }

        return $data;
    }

    /**
     * Whether a request is the classic form saving this post: not an autosave, same post, valid nonce, a grouped type.
     *
     * @param array $request Posted form, slashed.
     */
    private static function isClassicSave(int $postId, array $request): bool
    {
        if ($postId <= 0 || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return false;
        }

        if ((int) ($request['post_ID'] ?? 0) !== $postId) {
            return false;
        }

        $nonce = $request[ClassicEditor::NONCE_FIELD] ?? '';

        if (!is_string($nonce) || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), ClassicEditor::NONCE_ACTION)) {
            return false;
        }

        return FieldGroups::groupsFor((string) get_post_type($postId)) !== [];
    }

    /**
     * Get the live location params the form submits; core stores the template and terms only after `wp_insert_post_data`.
     *
     * @param array $data    Post data about to be stored.
     * @param array $postarr Posted form, slashed.
     * @return array<string, mixed>
     */
    private static function submittedLive(int $postId, string $postType, array $data, array $postarr): array
    {
        $live = [
            'post_status' => (string) $data['post_status'],
            'post_parent' => (int) ($data['post_parent'] ?? 0),
        ];

        if (isset($postarr['page_template'])) {
            $template              = sanitize_text_field(wp_unslash((string) $postarr['page_template']));
            $live['post_template'] = $template === 'default' ? '' : $template;
        }

        if (isset($postarr['post_format'])) {
            $format              = sanitize_key(wp_unslash((string) $postarr['post_format']));
            $live['post_format'] = in_array($format, ['', '0'], true) ? 'standard' : $format;
        }

        $terms = self::submittedTerms(is_array($postarr['tax_input'] ?? null) ? $postarr['tax_input'] : []);

        if ($terms !== []) {
            $live['post_terms'] = Location::terms($postId, $postType, $terms);
        }

        return $live;
    }

    /**
     * Get the term IDs the form assigns, by taxonomy, for the taxonomies the current user may assign.
     *
     * @param array $taxInput Posted `tax_input`, slashed.
     * @return array<string, list<int>>
     */
    private static function submittedTerms(array $taxInput): array
    {
        $terms = [];

        foreach ($taxInput as $slug => $value) {
            $taxonomy = get_taxonomy((string) $slug);

            if ($taxonomy instanceof WP_Taxonomy && current_user_can($taxonomy->cap->assign_terms)) {
                $terms[$taxonomy->name] = self::termIds($taxonomy, wp_unslash($value));
            }
        }

        return $terms;
    }

    /**
     * Resolve one taxonomy's posted terms to IDs: hierarchical boxes post IDs, tag boxes post comma-separated names.
     *
     * @return list<int>
     */
    private static function termIds(WP_Taxonomy $taxonomy, mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        if ($taxonomy->hierarchical) {
            return array_values(array_filter(array_map('intval', $values)));
        }

        $ids = [];

        foreach ($values as $name) {
            $term = get_term_by('name', trim((string) $name), $taxonomy->name);

            if ($term instanceof WP_Term) {
                $ids[] = $term->term_id;
            }
        }

        return $ids;
    }

    /**
     * Decode each group's posted JSON, keeping only the keys the current user may write that the group owns.
     *
     * @param array                $request Posted form, slashed.
     * @param array<string, mixed> $live    Live location params the save sets, before core stores them.
     * @return array<string, mixed> Values by meta key.
     */
    private static function incoming(int $postId, string $postType, array $request, array $live = []): array
    {
        $posted   = $request[ClassicEditor::INPUT] ?? [];
        $writable = MetaRegistrar::writableKeys($postType, $postId, wp_get_current_user(), $live);
        $incoming = [];

        if (!is_array($posted)) {
            return [];
        }

        foreach ($posted as $groupId => $json) {
            $values = is_string($json) ? json_decode(wp_unslash($json), true) : null;

            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $key => $value) {
                if (($writable[(string) $key] ?? null) === (string) $groupId) {
                    $incoming[(string) $key] = $value;
                }
            }
        }

        return $incoming;
    }

    /**
     * Write one value through Meta; null deletes it, as a REST meta update does.
     */
    private static function write(int $postId, string $key, mixed $value): void
    {
        if ($value === null) {
            Meta::forget($postId, $key);

            return;
        }

        Meta::set($postId, $key, $value);
    }

    /**
     * Keep a failed save's errors and rejected values for the edit screen the user is redirected to, or clear stale ones.
     *
     * @param array<string, mixed> $errors   Errors by field id.
     * @param array<string, mixed> $rejected Values not written, by field id.
     */
    private static function storeFailure(int $postId, array $errors, array $rejected): void
    {
        $key = self::errorsKey(get_current_user_id(), $postId);

        if ($errors === []) {
            delete_transient($key);

            return;
        }

        set_transient($key, ['errors' => $errors, 'values' => $rejected], self::ERRORS_TTL);
    }
}
