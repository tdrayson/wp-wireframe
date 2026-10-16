<?php

declare(strict_types=1);

namespace Wireframe\PostFields\Cli;

use WP_CLI;
use Wireframe\Meta;

/**
 * `wp wireframe reindex`: rebuild the repeater index after marking subfields `indexed`.
 */
final class ReindexCommand
{
    /**
     * Register the command when WP-CLI is running.
     */
    public static function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        WP_CLI::add_command('wireframe reindex', self::class, [
            'shortdesc' => 'Rebuild the index table for repeater subfields marked indexed.',
            'synopsis'  => [
                ['type' => 'assoc', 'name' => 'post_type', 'description' => 'Only reindex this post type.', 'optional' => true],
            ],
        ]);
    }

    /**
     * Run the reindex.
     *
     * @param array $args  Positional args (unused).
     * @param array $assoc `--post_type`.
     */
    public function __invoke(array $args, array $assoc): void
    {
        $postType = isset($assoc['post_type']) ? (string) $assoc['post_type'] : null;

        WP_CLI::success(sprintf('Reindexed %d posts.', Meta::reindex($postType)));
    }
}
