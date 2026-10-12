<?php

/**
 * Namespaces this copy autoloads, mapped to directories relative to the copy root.
 *
 * Bundled dependencies list two roots: nested vendor/ in the release zip, then the
 * sibling package in a Composer install. release.yml fails if one is missing here.
 */

return [
    'Wireframe\\'         => ['src/'],
    'Rakit\\Validation\\' => ['vendor/magewirephp/validation/src/', '../../magewirephp/validation/src/'],
];
