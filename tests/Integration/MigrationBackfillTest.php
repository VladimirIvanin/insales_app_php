<?php

declare(strict_types=1);

namespace Tests\Integration;

use Infrastructure\Database;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[TestDox('Generation backfill for databases created before migration 004')]
final class MigrationBackfillTest extends TestCase
{
    #[TestDox('upgrade from schema v3 links pending candidates and stores to generations')]
    public function testUpgradeFromV3LinksPendingCandidates(): void
    {
        $db = new Database(':memory:');
        /** @var array<int, string> $migrations */
        $migrations = require __DIR__ . '/../../migrations/migrate.php';
        $db->migrate($migrations, 3);

        $db->execute(
            'INSERT INTO stores (store_id, shop, encrypted_password, title, generation, installed_at)
             VALUES (:id, :shop, :password, :title, :generation, :installed)',
            [
                'id' => '7',
                'shop' => 'store.example',
                'password' => 'cipher',
                'title' => 'Store',
                'generation' => 3,
                'installed' => 50,
            ],
        );
        $db->execute(
            'INSERT INTO install_candidates (id, store_id, shop, encrypted_password, candidate_version, created_at)
             VALUES (:id, :store_id, :shop, :password, :version, :created)',
            [
                'id' => 'cand-1',
                'store_id' => '42',
                'shop' => 'test.myshop.ru',
                'password' => 'cipher-a',
                'version' => 1,
                'created' => 10,
            ],
        );
        $db->execute(
            'INSERT INTO install_candidates (id, store_id, shop, encrypted_password, candidate_version, created_at)
             VALUES (:id, :store_id, :shop, :password, :version, :created)',
            [
                'id' => 'cand-2',
                'store_id' => '42',
                'shop' => 'test.myshop.ru',
                'password' => 'cipher-b',
                'version' => 2,
                'created' => 11,
            ],
        );

        $db->migrate($migrations);

        /** @var array{status: string}|null $storeGeneration */
        $storeGeneration = $db->fetch(
            'SELECT status FROM installation_generations WHERE store_id = :id AND generation = 3',
            ['id' => '7'],
        );
        $this->assertNotNull($storeGeneration);
        $this->assertSame('active', $storeGeneration['status']);

        $linked = $db->fetchAll(
            'SELECT c.id
             FROM install_candidates c
             JOIN installation_generations ig
               ON ig.store_id = c.store_id AND ig.candidate_id = c.id
             WHERE c.store_id = :id AND ig.status = :reserved
             ORDER BY ig.generation',
            ['id' => '42', 'reserved' => 'reserved'],
        );
        $ids = [];
        foreach ($linked as $row) {
            $id = $row['id'] ?? null;
            if (!is_string($id)) {
                self::fail('Candidate id is missing');
            }
            $ids[] = $id;
        }
        $this->assertSame(['cand-1', 'cand-2'], $ids);
    }
}
