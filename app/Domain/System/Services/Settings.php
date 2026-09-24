<?php

namespace App\Domain\System\Services;

use App\Domain\System\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Typed access to the settings table with defaults from config/pos.php.
 */
class Settings
{
    private const CACHE_KEY = 'pos.settings';

    /**
     * Read one setting, e.g. get('receipt.language').
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    /**
     * All settings of one group, merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        return $this->all()[$group] ?? [];
    }

    /**
     * Save several settings of one group.
     *
     * @param  array<string, mixed>  $values
     */
    public function setGroup(string $group, array $values): void
    {
        DB::transaction(function () use ($group, $values): void {
            foreach ($values as $key => $value) {
                $setting = Setting::firstOrNew(['group' => $group, 'key' => $key]);
                $setting->value = $value;
                $setting->save();
            }
        });

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, function (): array {
            $values = [];

            foreach (Setting::query()->get(['group', 'key', 'value']) as $setting) {
                $values[$setting->group][$setting->key] = $setting->value;
            }

            return $values;
        });

        return array_replace_recursive(config('pos.settings_defaults', []), $stored);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
