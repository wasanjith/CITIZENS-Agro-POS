<?php

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SearchSynonym;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Meilisearch\Client;

/**
 * Pushes the whole search_synonyms table to the Meilisearch products index.
 * Replaces the index's synonyms, so deletes are picked up too.
 */
class SyncSearchSynonymsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function handle(): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }

        app(Client::class)
            ->index((new Product)->searchableAs())
            ->updateSynonyms(SearchSynonym::toMeilisearch());
    }
}
