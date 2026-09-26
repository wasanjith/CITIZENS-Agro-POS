<?php

namespace App\Domain\Reports\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\Terminal;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\User;

/**
 * Names for the ids in report rows, and the options of the common filters.
 * Loaded once per request.
 */
class ReportLookups
{
    /** @var array<string, array<int, string>> */
    private array $cache = [];

    /**
     * @return array<int, string>
     */
    public function terminals(): array
    {
        return $this->cache['terminals'] ??= Terminal::query()->orderBy('type')->orderBy('counter_no')->orderBy('name')->get()
            ->mapWithKeys(fn (Terminal $terminal) => [$terminal->id => $terminal->displayName()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function users(): array
    {
        return $this->cache['users'] ??= User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public function categories(): array
    {
        return $this->cache['categories'] ??= Category::query()->with('parent.parent')->get()
            ->mapWithKeys(fn (Category $category) => [$category->id => $category->path()])
            ->sort()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function brands(): array
    {
        return $this->cache['brands'] ??= Brand::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public function suppliers(): array
    {
        return $this->cache['suppliers'] ??= Supplier::withTrashed()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Customer>
     */
    public function customers(array $ids): array
    {
        return Customer::withTrashed()->whereIn('id', array_unique(array_filter($ids)))->get()->keyBy('id')->all();
    }

    public function terminal(?int $id): string
    {
        return $id !== null ? ($this->terminals()[$id] ?? "#{$id}") : '';
    }

    public function user(?int $id): string
    {
        return $id !== null ? ($this->users()[$id] ?? "#{$id}") : '';
    }
}
