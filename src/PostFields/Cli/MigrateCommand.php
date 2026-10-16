<?php

declare(strict_types=1);

namespace Wireframe\PostFields\Cli;

use WP_CLI;
use Wireframe\Meta;

/**
 * `wp wireframe migrate`: move values stored under a field's `previous_ids` to its current key.
 */
final class MigrateCommand
{
    /**
     * Register the command when WP-CLI is running.
     */
    public static function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        WP_CLI::add_command('wireframe migrate', self::class, [
            'shortdesc' => 'Move values stored under renamed fields\' previous ids to their current keys.',
            'synopsis'  => [
                ['type' => 'assoc', 'name' => 'post_type', 'description' => 'Only migrate this post type.', 'optional' => true],
            ],
        ]);
    }

    /**
     * Run the migration.
     *
     * @param array $args  Positional args (unused).
     * @param array $assoc `--post_type`.
     */
    public function __invoke(array $args, array $assoc): void
    {
        $postType = isset($assoc['post_type']) ? (string) $assoc['post_type'] : null;

        WP_CLI::success(sprintf('Migrated %d posts.', Meta::migrate($postType)));
    }
}
