<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Infrastructure\Storage;

use DateTimeImmutable;
use PeanutAdmin\FileMedia\Delivery\ReplayGuard;
use think\facade\Db;

final class DatabaseReplayGuard implements ReplayGuard
{
    public function consume(string $tokenId, DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $tokenId) !== 1 || $expiresAt <= $now) {
            return false;
        }
        return Db::transaction(function () use ($tokenId, $expiresAt, $now): bool {
            Db::name('file_delivery_nonce')->where('expires_at', '<=', $now->format('Y-m-d H:i:s.v'))->delete();
            try {
                $consumed = Db::name('file_delivery_nonce')->insert([
                    'token_id_hash' => hash('sha256', $tokenId),
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s.v'),
                    'consumed_at' => $now->format('Y-m-d H:i:s.v'),
                ]) === 1;
                return $consumed;
            } catch (\Throwable $exception) {
                if (str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                    return false;
                }
                throw $exception;
            }
        });
    }
}
