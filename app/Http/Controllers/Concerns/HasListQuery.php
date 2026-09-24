<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared behaviour for list pages: ?search=&sort=&dir=&filter[...]=
 */
trait HasListQuery
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $searchable  columns matched with LIKE
     * @param  list<string>  $sortable  columns allowed in ?sort=
     * @param  array<string, callable(Builder<TModel>, string): void>  $filters  ?filter[name]=value handlers
     * @return Builder<TModel>
     */
    protected function applyListQuery(
        Builder $query,
        Request $request,
        array $searchable = [],
        array $sortable = [],
        array $filters = [],
        string $defaultSort = 'id',
        string $defaultDirection = 'desc',
    ): Builder {
        $search = trim((string) $request->query('search', ''));

        if ($search !== '' && $searchable !== []) {
            $query->where(function (Builder $inner) use ($searchable, $search): void {
                foreach ($searchable as $column) {
                    $inner->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        foreach ((array) $request->query('filter', []) as $name => $value) {
            if (isset($filters[$name]) && $value !== null && $value !== '') {
                $filters[$name]($query, (string) $value);
            }
        }

        $sort = (string) $request->query('sort', $defaultSort);
        $direction = strtolower((string) $request->query('dir', $defaultDirection)) === 'asc' ? 'asc' : 'desc';

        if (! in_array($sort, $sortable, true)) {
            $sort = $defaultSort;
        }

        return $query->orderBy($sort, $direction);
    }
}
