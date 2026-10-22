<?php

/**
 * Database migrations index.
 *
 * Returns array of version => SQL pairs.
 * Add new migrations at the end with incremented version.
 */

declare(strict_types=1);

return [
    1 => file_get_contents(__DIR__ . '/001_initial_schema.sql'),
    2 => file_get_contents(__DIR__ . '/002_fix_install_candidates_fk.sql'),
    3 => file_get_contents(__DIR__ . '/003_add_candidate_version.sql'),
];