<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Jobs\SyncSearchSynonymsJob;
use App\Domain\Catalog\Policies\SearchSynonymPolicy;
use Database\Factories\SearchSynonymFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * "tsp" ↔ "triple super phosphate". Pushed to Meilisearch by SyncSearchSynonymsJob.
 *
 * @property int $id
 * @property string $term
 * @property list<string> $synonyms
 */
#[Fillable(['term', 'synonyms'])]
#[UseFactory(SearchSynonymFactory::class)]
#[UsePolicy(SearchSynonymPolicy::class)]
class SearchSynonym extends Model
{
    /** @use HasFactory<SearchSynonymFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'synonyms' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => SyncSearchSynonymsJob::dispatch()->afterCommit());
        static::deleted(fn () => SyncSearchSynonymsJob::dispatch()->afterCommit());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['term', 'synonyms'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Two-way synonyms in Meilisearch format: every word points to all the others.
     *
     * @return array<string, list<string>>
     */
    public static function toMeilisearch(): array
    {
        $map = [];

        foreach (static::all() as $synonym) {
            $group = array_values(array_unique([$synonym->term, ...$synonym->synonyms]));

            foreach ($group as $word) {
                $others = array_values(array_diff($group, [$word]));
                $map[$word] = array_values(array_unique([...($map[$word] ?? []), ...$others]));
            }
        }

        return $map;
    }
}
