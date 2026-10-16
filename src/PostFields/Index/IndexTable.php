<?php

declare(strict_types=1);

namespace Wireframe\PostFields\Index;

/**
 * The side table that mirrors indexed repeater subfields, shared by every bundled copy of Wireframe.
 */
final class IndexTable
{
    /** Schema version this copy ships. */
    public const VERSION = 1;

    public const OPTION = 'wireframe_index_db_version';

    /**
     * Get the table name for the current site.
     */
    public static function name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wireframe_index';
    }

    /**
     * Create or upgrade the table when the stored version is below this copy's; never lowers the stored version.
     */
    public static function install(): void
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return;
        }

        global $wpdb;

        $table   = self::name();
        $collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            field VARCHAR(64) NOT NULL,
            row_index INT UNSIGNED NOT NULL,
            subfield VARCHAR(64) NOT NULL,
            value_str VARCHAR(191) NULL,
            value_num DECIMAL(20,6) NULL,
            value_date DATETIME NULL,
            PRIMARY KEY  (id),
            KEY post_field (post_id,field),
            KEY lookup_str (field,subfield,value_str),
            KEY lookup_num (field,subfield,value_num),
            KEY lookup_date (field,subfield,value_date)
        ) {$collate};");

        // Left unbumped on failure, so the next request retries.
        if (!self::exists()) {
            return;
        }

        // Another copy may have installed a newer schema while dbDelta ran.
        if ((int) get_option(self::OPTION, 0) < self::VERSION) {
            update_option(self::OPTION, self::VERSION);
        }
    }

    /**
     * Whether the current site's table exists.
     */
    public static function exists(): bool
    {
        global $wpdb;

        $table = self::name();

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }
}
