<?php

use Wireframe\Meta;
use Wireframe\PostFields\FieldGroups;
use Wireframe\PostFields\ValueFormatter;

require __DIR__ . '/assert.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

as_user('administrator');

$notices = [];
add_action('doing_it_wrong_run', static function (string $function, string $message) use (&$notices): void {
    $notices[] = $message;
}, 10, 2);

register_post_type('bind_cpt', ['public' => true, 'show_in_rest' => true, 'supports' => ['title']]);
FieldGroups::register('wf-bind', [
    'id'       => 'bind_group',
    'title'    => 'Bind',
    'location' => ['bind_cpt'],
    'config'   => ['fields' => [
        ['id' => 'subtitle', 'type' => 'text', 'public' => true, 'default' => 'No subtitle'],
        ['id' => 'starts', 'type' => 'date', 'public' => true],
        ['id' => 'featured', 'type' => 'toggle', 'public' => true],
        ['id' => 'genres', 'type' => 'checkboxes', 'public' => true, 'args' => ['options' => ['jazz' => 'Jazz', 'rock' => 'Rock']]],
        ['id' => 'logo', 'type' => 'file', 'public' => true],
        ['id' => 'hidden', 'type' => 'text'],
        [
            'id'     => 'rows',
            'type'   => 'repeater',
            'public' => true,
            'args'   => ['subfields' => [
                ['id' => 'venue', 'type' => 'text'],
                ['id' => 'when', 'type' => 'date'],
                ['id' => 'on', 'type' => 'toggle'],
            ]],
        ],
    ]],
]);

$created = [];
$make    = static function (array $meta = []) use (&$created): int {
    $id        = wp_insert_post(['post_type' => 'bind_cpt', 'post_status' => 'publish', 'post_title' => 'Bound', 'meta_input' => $meta]);
    $created[] = $id;

    return $id;
};

/** Render a block bound to `$key` on `$attribute`, with post context. */
$render = static function (string $block, string $attribute, array $args, ?int $postId, string $html, string $postType = 'bind_cpt') use (&$notices): string {
    $notices = [];
    $attrs   = ['metadata' => ['bindings' => [$attribute => ['source' => 'wireframe/field', 'args' => $args]]]];
    $markup  = '<!-- wp:' . $block . ' ' . wp_json_encode($attrs) . ' -->' . $html . '<!-- /wp:' . $block . ' -->';
    $parsed  = parse_blocks($markup)[0];
    $context = $postId === null ? [] : ['postId' => $postId, 'postType' => $postType];

    return (new WP_Block($parsed, $context))->render();
};
$para = static fn (array $args, ?int $postId, string $postType = 'bind_cpt'): string => $render('paragraph', 'content', $args, $postId, '<p></p>', $postType);
$bare = static fn (string $html): string => trim((string) preg_replace('/ class="wp-block-paragraph"/', '', $html));
$text = static fn (string $html): string => trim(wp_strip_all_tags($html));

check('the source is registered', true, get_block_bindings_source('wireframe/field') !== null);
check('the source declares its context', ['postId', 'postType'], get_block_bindings_source('wireframe/field')->uses_context);

// Public fields.
$id = $make(['subtitle' => 'Hello there', 'starts' => '2026-03-05']);
check('a public field renders', 'Hello there', $text($para(['key' => 'subtitle'], $id)));
check('it renders inside the paragraph', '<p>Hello there</p>', $bare($para(['key' => 'subtitle'], $id)));
check('an unset field renders its default', 'No subtitle', $text($para(['key' => 'subtitle'], $make())));
check('the event fixture tagline renders', 'Tagline!', $text($para(['key' => 'public_tagline'], make_event(['public_tagline' => 'Tagline!'], 'publish'), 'event')));

update_post_meta($id, 'subtitle', 'Fish & Chips');
check('plain text is escaped for rich text', 'Fish &amp; Chips', $text($para(['key' => 'subtitle'], $id)));
update_post_meta($id, 'subtitle', 'Hello there');

// Refusals.
$wrongRun = defined('WP_DEBUG') && WP_DEBUG;
$secret   = make_event(['secret_code' => 'hunter2'], 'publish');
$out      = $para(['key' => 'secret_code'], $secret, 'event');
check('a private field renders empty', '<p></p>', $bare($out));
check('a private field is not leaked', false, str_contains($out, 'hunter2'));
check('a private field warns under WP_DEBUG', $wrongRun, count($notices) === 1 && str_contains($notices[0], 'secret_code'));
$created[] = $secret;

check('a field without the public flag renders empty', '<p></p>', $bare($para(['key' => 'hidden'], $make(['hidden' => 'x']))));
check('an unknown key renders empty', '<p></p>', $bare($para(['key' => 'nope'], $id)));
check('an unknown key warns under WP_DEBUG', $wrongRun, count($notices) === 1);
check('a missing key renders empty', '<p></p>', $bare($para([], $id)));
check('a missing key warns under WP_DEBUG', $wrongRun, count($notices) === 1);
check('no post context renders empty', '<p></p>', $bare($para(['key' => 'subtitle'], null)));
check('a field of another post type renders empty', '<p></p>', $bare($para(['key' => 'subtitle'], $id, 'event')));

// Per-type formatting.
check('a date uses the site format', wp_date(get_option('date_format'), strtotime('2026-03-05 UTC'), new DateTimeZone('UTC')), $text($para(['key' => 'starts'], $id)));
check('a date takes a PHP format', '2026/03/05', $text($para(['key' => 'starts', 'format' => 'Y/m/d'], $id)));
check('format is ignored for other types', 'Hello there', $text($para(['key' => 'subtitle', 'format' => 'Y'], $id)));
check('an empty date is empty', '<p></p>', $bare($para(['key' => 'starts'], $make())));

$on = $make(['featured' => true]);
Meta::set($on, 'genres', ['jazz', 'rock']);
check('toggle on is Yes', 'Yes', $text($para(['key' => 'featured'], $on)));
check('toggle off is No', 'No', $text($para(['key' => 'featured'], $make(['featured' => false]))));
check('choices render their labels', 'Jazz, Rock', $text($para(['key' => 'genres'], $on)));

// Files.
$pngPath = wp_tempnam('wf-bind.png');
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$image     = media_handle_sideload(['name' => 'wf-bind.png', 'tmp_name' => $pngPath], 0);
$created[] = $image;
$withLogo  = $make(['logo' => $image]);
$imageHtml = '<figure class="wp-block-image"><img alt=""/></figure>';
$bound     = $render('image', 'url', ['key' => 'logo'], $withLogo, $imageHtml);
check('a file bound to an image url is the attachment URL', true, str_contains($bound, 'src="' . esc_url(wp_get_attachment_url($image)) . '"'));
check('a file bound to text is the URL', wp_get_attachment_url($image), $text($para(['key' => 'logo'], $withLogo)));
check('an empty file is empty', '<p></p>', $bare($para(['key' => 'logo'], $make())));

// Dotted paths.
$rows = $make(['rows' => [
    ['venue' => 'Leeds', 'when' => '2026-11-05', 'on' => true],
    ['venue' => 'York', 'when' => '2026-10-01', 'on' => false],
]]);
check('a dot path reads a cell', 'York', $text($para(['key' => 'rows.1.venue'], $rows)));
check('a dot path formats with the subfield type', wp_date(get_option('date_format'), strtotime('2026-11-05 UTC'), new DateTimeZone('UTC')), $text($para(['key' => 'rows.0.when'], $rows)));
check('a dot path toggle is Yes/No', 'No', $text($para(['key' => 'rows.1.on'], $rows)));
check('a dot path without a row reads the first row', 'Leeds', $text($para(['key' => 'rows.venue'], $rows)));
check('an out-of-range row is empty', '<p></p>', $bare($para(['key' => 'rows.9.venue'], $rows)));
check('an unknown subfield is empty', '<p></p>', $bare($para(['key' => 'rows.0.nope'], $rows)));
check('an unknown subfield warns under WP_DEBUG', $wrongRun, count($notices) === 1);
check('a top-level repeater counts its rows', '2 rows', $text($para(['key' => 'rows'], $rows)));

$sessions = make_event(['sessions' => [['venue' => 'Leeds']]], 'publish');
$created[] = $sessions;
check('a dot path under a non-public repeater is empty', '<p></p>', $bare($para(['key' => 'sessions.0.venue'], $sessions, 'event')));
check('a dot path under a non-public repeater warns', $wrongRun, count($notices) === 1);

// Formatter.
$field = static fn (string $postType, string $id): array => FieldGroups::flatFields($postType)[$id];
check('formatter: toggle', 'Yes', ValueFormatter::text(['type' => 'toggle'], true));
check('formatter: checkbox', 'No', ValueFormatter::text(['type' => 'checkbox'], false));
check('formatter: number', '9', ValueFormatter::text(['type' => 'number'], 9));
check('formatter: long text is not cut', 300, mb_strlen(ValueFormatter::text(['type' => 'textarea'], str_repeat('é', 300))));
check('formatter: text keeps line breaks', "a\nb", ValueFormatter::text(['type' => 'textarea'], "a\nb"));
check('formatter: file url', wp_get_attachment_url($image), ValueFormatter::text($field('bind_cpt', 'logo'), $image, 'url'));
check('formatter: no file', '', ValueFormatter::text($field('bind_cpt', 'logo'), 0, 'url'));
check('formatter: choice labels', 'Jazz', ValueFormatter::text($field('bind_cpt', 'genres'), ['jazz']));

foreach (array_reverse($created) as $postId) {
    wp_delete_post($postId, true);
}

finish();
