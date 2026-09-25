<?php

namespace App\Domain\Sales\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The cart being built on each counter, kept in Redis (the cache store) as
 * live_cart:{terminal_id} for 12 hours. It restores the cart after a browser refresh
 * or power cut and feeds Live Billing. The invoice is always repriced on the server.
 *
 * Also keeps a heartbeat per counter (who is signed in, when it last synced) and the
 * voided invoices a counter has not restored yet.
 */
class LiveCartStore
{
    private const TTL_SECONDS = 12 * 60 * 60;

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $terminalId): ?array
    {
        $cart = Cache::get($this->key($terminalId));

        return is_array($cart) ? $cart : null;
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    public function put(int $terminalId, array $cart): void
    {
        Cache::put($this->key($terminalId), $cart, self::TTL_SECONDS);
    }

    public function forget(int $terminalId): void
    {
        Cache::forget($this->key($terminalId));
    }

    public function touch(int $terminalId, int $userId, string $userName): void
    {
        Cache::put("live_heartbeat:{$terminalId}", ['user_id' => $userId, 'user' => $userName, 'at' => now()->toIso8601String()], self::TTL_SECONDS);
    }

    /**
     * @return array{user_id: int, user: string, at: string}|null
     */
    public function heartbeat(int $terminalId): ?array
    {
        $beat = Cache::get("live_heartbeat:{$terminalId}");

        return is_array($beat) ? $beat : null;
    }

    public function signOff(int $terminalId): void
    {
        Cache::forget("live_heartbeat:{$terminalId}");
    }

    /**
     * Voided invoices waiting for the counter to restore or dismiss their cart.
     *
     * @return list<array<string, mixed>>
     */
    public function voidRestores(int $terminalId): array
    {
        $items = Cache::get("void_restore:{$terminalId}");

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @param  array<string, mixed>  $restore
     */
    public function pushVoidRestore(int $terminalId, array $restore): void
    {
        $items = collect($this->voidRestores($terminalId))
            ->reject(fn (array $item) => ($item['sale_id'] ?? null) === $restore['sale_id'])
            ->push($restore)
            ->take(-10)
            ->values()
            ->all();

        Cache::put("void_restore:{$terminalId}", $items, self::TTL_SECONDS);
    }

    public function dismissVoidRestore(int $terminalId, int $saleId): void
    {
        $items = collect($this->voidRestores($terminalId))
            ->reject(fn (array $item) => (int) ($item['sale_id'] ?? 0) === $saleId)
            ->values()
            ->all();

        Cache::put("void_restore:{$terminalId}", $items, self::TTL_SECONDS);
    }

    private function key(int $terminalId): string
    {
        return "live_cart:{$terminalId}";
    }
}
