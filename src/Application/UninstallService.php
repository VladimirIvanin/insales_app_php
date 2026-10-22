<?php

declare(strict_types=1);

namespace Application;

use InSales\Protocol;
use InSales\ShopValidator;
use Infrastructure\Database;
use Infrastructure\Encryption;

/**
 * Service handling application uninstall.
 *
 * Verifies uninstall token (which is the store password),
 * atomically removes credentials, invalidates sessions, challenges,
 * and pending install candidates.
 *
 * Re-delivery: repeated uninstall for already removed store returns 200
 * (idempotent). Old uninstall callback after reinstall is rejected because
 * token comparison uses the old password which won't match the new generation.
 *
 * Авторизация uninstall выполняется внутри транзакции, чтобы исключить
 * race condition между проверкой token и cleanup.
 * При наличии candidate проверяется token по его credential,
 * а не по активной store, чтобы uninstall для нового поколения
 * не был ошибочно отвергнут.
 */
final readonly class UninstallService
{
    /**
     * @param array<int, string> $allowedShops
     */
    public function __construct(
        private Database $db,
        private Encryption $encryption,
        private array $allowedShops,
        private bool $allowHttp,
    ) {
    }

    /**
     * Handle uninstall callback from InSales.
     *
     * Всегда чистит install_candidates, generations и jobs для store_id/shop,
     * даже если строки stores ещё нет (неактивированная установка).
     * Вся проверка авторизации — внутри транзакции.
     *
     * Авторизация по паролю active store — полная очистка (stores, sessions, candidates, generations).
     * Авторизация по паролю кандидата — удаляет только этот кандидат и его reserved поколение.
     *
     * @return bool True if uninstall was processed, false if nothing to remove (and token matches — no-op)
     * @throws \InvalidArgumentException
     */
    public function handleUninstall(string $shop, string $token, string $insalesId): bool
    {
        $shop = ShopValidator::validate($shop, $this->allowedShops, $this->allowHttp);

        if (!ctype_digit($insalesId)) {
            throw new \InvalidArgumentException('insales_id must be numeric');
        }

        // Вся проверка авторизации и cleanup — в одной транзакции
        $processed = false;
        $authorized = false;
        $matchedCandidateId = null;

        $this->db->transaction(function () use ($insalesId, $shop, $token, &$processed, &$authorized, &$matchedCandidateId) {
            // 1. Пытаемся авторизоваться по активной store
            $store = $this->db->fetch(
                'SELECT * FROM stores WHERE store_id = :store_id AND shop = :shop',
                ['store_id' => $insalesId, 'shop' => $shop],
            );

            if ($store !== null) {
                $storePassword = $this->encryption->decrypt((string) $store['encrypted_password']);
                if (hash_equals($storePassword, $token)) {
                    $authorized = true;
                    $processed = true;

                    $this->db->execute(
                        'DELETE FROM sessions WHERE store_id = :store_id',
                        ['store_id' => $insalesId],
                    );

                    $this->db->execute(
                        'DELETE FROM pending_logins WHERE store_id = :store_id',
                        ['store_id' => $insalesId],
                    );

                    $this->db->execute(
                        'DELETE FROM stores WHERE store_id = :store_id',
                        ['store_id' => $insalesId],
                    );
                }
            }

            // 2. Если не авторизовались по store, пробуем по candidate
            // Перебираем ВСЕ candidates: при нескольких pending один из них может
            // иметь правильный пароль.
            if (!$authorized) {
                $candidates = $this->db->fetchAll(
                    'SELECT * FROM install_candidates WHERE store_id = :store_id AND shop = :shop',
                    ['store_id' => $insalesId, 'shop' => $shop],
                );

                foreach ($candidates as $candidate) {
                    $candidatePassword = $this->encryption->decrypt((string) $candidate['encrypted_password']);
                    if (hash_equals($candidatePassword, $token)) {
                        $authorized = true;
                        $matchedCandidateId = (string) $candidate['id'];
                        break;
                    }
                }
            }

            if (!$authorized) {
                throw new \InvalidArgumentException('Invalid uninstall token');
            }

            if ($matchedCandidateId !== null) {
                // Uninstall по паролю кандидата (активная установка ещё не завершена):
                // удаляем только совпавший кандидат и его reserved поколение.
                // Active поколение и чужих кандидатов не трогаем.
                $this->db->execute(
                    'DELETE FROM install_candidates WHERE id = :id',
                    ['id' => $matchedCandidateId],
                );

                $this->db->execute(
                    'UPDATE installation_generations SET status = :cancelled
                     WHERE store_id = :store_id AND candidate_id = :candidate_id AND status = :reserved',
                    [
                        'cancelled' => 'cancelled',
                        'store_id' => $insalesId,
                        'candidate_id' => $matchedCandidateId,
                        'reserved' => 'reserved',
                    ],
                );
            } else {
                // Uninstall по паролю активной store — полная очистка
                $this->db->execute(
                    'DELETE FROM install_candidates WHERE store_id = :store_id AND shop = :shop',
                    ['store_id' => $insalesId, 'shop' => $shop],
                );

                // Cancel pending generations
                $this->db->execute(
                    'UPDATE installation_generations SET status = :cancelled
                     WHERE store_id = :store_id AND status = :reserved',
                    [
                        'cancelled' => 'cancelled',
                        'store_id' => $insalesId,
                        'reserved' => 'reserved',
                    ],
                );

                // Отменяем активное поколение
                $this->db->execute(
                    'UPDATE installation_generations SET status = :cancelled
                     WHERE store_id = :store_id AND status = :active',
                    [
                        'cancelled' => 'cancelled',
                        'store_id' => $insalesId,
                        'active' => 'active',
                    ],
                );
            }

            // Отменяем задачи очереди для этого магазина
            $this->db->execute(
                "UPDATE jobs SET status = 'failed', error = :error, completed_at = :now
                 WHERE store_id = :store_id AND status IN ('pending', 'running')",
                [
                    'store_id' => $insalesId,
                    'error' => 'Uninstalled',
                    'now' => time(),
                ],
            );
        });

        return $processed;
    }
}
