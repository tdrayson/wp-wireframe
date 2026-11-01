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
    /** Filter each bundled copy adds its own count to, so the one registered command covers every copy. */
    public const FILTER = 'wireframe/cli_migrate';

    /**
     * Hook this copy into the command when WP-CLI is running, registering it unless another bundled copy has.
     */
    public static function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        add_filter(self::FILTER, [self::class, 'runCopy'], 10, 2);

        if (!empty($GLOBALS['wireframe_cli_commands']['migrate'])) {
            return;
        }

        $GLOBALS['wireframe_cli_commands']['migrate'] = true;

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

        WP_CLI::success(sprintf('Migrated %d posts.', (int) apply_filters(self::FILTER, 0, $postType)));
    }

    /**
     * Filter `wireframe/cli_migrate`: run this copy's migrate and add its count.
     *
     * @param mixed       $count    Posts the other copies processed.
     * @param string|null $postType Post type slug, or null for all.
     * @return int
     */
    public static function runCopy(mixed $count, ?string $postType): int
    {
        return (int) $count + Meta::migrate($postType);
    }
}
