<?php

use Wireframe\Meta;

require __DIR__ . '/assert.php';

$wrong = [];
add_action('doing_it_wrong_run', static function (string $function) use (&$wrong): void {
    $wrong[] = $function;
});

$row = ['starts' => '2026-11-01', 'venue' => 'York', 'seats' => 40, 'notes' => 'keep', 'tags' => ['a']];

$id = make_event();

check('config default for a scalar', true, Meta::get($id, 'is_featured'));
check('config default for a multi-value field', ['jazz'], Meta::get($id, 'genres'));
check('call-site default for an unset field', 'x', Meta::get($id, 'ticket_url', 'x'));
check('bool reads the default', true, Meta::bool($id, 'is_featured'));
check('int is 0 when unset', 0, Meta::int($id, 'price'));
check('has is false when unset', false, Meta::has($id, 'price'));

check('set a scalar', true, Meta::set($id, 'price', 15));
check('number read back typed', 15, Meta::get($id, 'price'));
check('setting an unchanged value still succeeds', true, Meta::set($id, 'price', 15));
check('has is true once set', true, Meta::has($id, 'price'));

Meta::set($id, 'is_featured', false);
check('false is stored, not replaced by the default', false, Meta::get($id, 'is_featured'));
Meta::toggle($id, 'is_featured');
check('toggle', true, Meta::get($id, 'is_featured'));

check('set a repeater', true, Meta::set($id, 'sessions', [$row]));
check('dot read into a repeater', 'York', Meta::get($id, 'sessions.0.venue'));
check('dot read of a missing cell uses the call-site default', 'none', Meta::get($id, 'sessions.5.venue', 'none'));
check('dot set updates one cell', true, Meta::set($id, 'sessions.0.venue', 'Hull'));
check('dot set changed the cell', 'Hull', Meta::get($id, 'sessions.0.venue'));
check('dot set left the other cells', ['2026-11-01', 'keep', ['a']], [
    Meta::get($id, 'sessions.0.starts'),
    Meta::get($id, 'sessions.0.notes'),
    Meta::get($id, 'sessions.0.tags'),
]);
check('dot forget succeeds', true, Meta::forget($id, 'sessions.0.notes'));
check('dot forget resets the cell', '', Meta::get($id, 'sessions.0.notes'));
check('push succeeds', true, Meta::push($id, 'sessions', $row));
check('push appends a repeater row', 2, count(Meta::array($id, 'sessions')));

check('multi-value set', true, Meta::set($id, 'genres', ['rock', 'jazz']));
check('multi-value stores one row per value', 2, count(get_post_meta($id, 'genres')));
check('multi-value read back', ['rock', 'jazz'], Meta::get($id, 'genres'));
Meta::set($id, 'genres', ['rock', 'metal']);
check('multi-value drops options the field does not have', ['rock'], Meta::get($id, 'genres'));
check('emptied multi-value set succeeds', true, Meta::set($id, 'genres', []));
check('emptied multi-value field stores no rows', [], get_post_meta($id, 'genres'));

Meta::set($id, 'public_tagline', 'a\\b');
check('backslashes survive the round trip', 'a\\b', get_post_meta($id, 'public_tagline', true));

Meta::set($id, 'logo', 12);
check('single file stored as an ID', 12, Meta::get($id, 'logo'));
check('single file row', '12', get_post_meta($id, 'logo', true));
Meta::set($id, 'gallery', [3, 1, 2]);
check('multiple files keep their order', [3, 1, 2], Meta::get($id, 'gallery'));

$wrong = [];
check('unknown key returns false', false, Meta::set($id, 'unknown_key', 'x'));
check('unknown key fires doing-it-wrong', true, in_array('Wireframe\Meta::set', $wrong, true));
check('unknown key reads as the default', 'd', Meta::get($id, 'unknown_key', 'd'));
check('a dotted key into a scalar is refused', false, Meta::set($id, 'price.x', 1));

$all = Meta::all($id);
check('all returns raw stored values', '15', $all['price'] ?? null);
check('all leaves unset fields out', false, array_key_exists('ticket_url', $all));
check('all leaves unregistered meta out', false, array_key_exists('_edit_lock', $all));
check('resolved merges defaults', true, Meta::resolved(make_event())['is_featured'] ?? null);
check('only', ['price' => 15], Meta::only($id, 'price', 'nope'));
check('except', false, array_key_exists('price', Meta::except($id, 'price')));
check('exists', true, Meta::exists($id));

check('update replaces what is stored', true, Meta::update($id, ['price' => 3, 'ticket_url' => 'https://example.com']));
check('update kept the given values', [3, 'https://example.com'], [Meta::get($id, 'price'), Meta::get($id, 'ticket_url')]);
check('update removed the rest', false, Meta::has($id, 'sessions'));

Meta::increment($id, 'price', 2);
check('increment', 5, Meta::get($id, 'price'));
Meta::decrement($id, 'price');
check('decrement', 4, Meta::get($id, 'price'));
check('pull returns then forgets', [4, false], [Meta::pull($id, 'price'), Meta::has($id, 'price')]);
check('getOrSet sets once', ['a', 'a'], [Meta::getOrSet($id, 'seo_title', 'a'), Meta::getOrSet($id, 'seo_title', 'b')]);

Meta::set($id, 'price', 9);
check('reset deletes every key', true, Meta::reset($id));
check('reset clears a scalar', '', get_post_meta($id, 'price', true));
check('reset clears a multi-value field', [], get_post_meta($id, 'genres'));
check('reset leaves nothing', false, Meta::exists($id));

finish();
