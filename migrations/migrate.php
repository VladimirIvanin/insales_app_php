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
    4 => file_get_contents(__DIR__ . '/004_add_generation_tracking.sql'),
    5 => file_get_contents(__DIR__ . '/005_add_lease_token.sql'),
    6 => file_get_contents(__DIR__ . '/006_link_challenge_to_generation.sql'),
    7 => file_get_contents(__DIR__ . '/007_backfill_generations_and_tombstones.sql'),
];