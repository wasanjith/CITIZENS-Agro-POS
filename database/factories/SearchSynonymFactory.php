<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\SearchSynonym;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchSynonym>
 */
class SearchSynonymFactory extends Factory
{
    protected $model = SearchSynonym::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term' => fake()->unique()->word(),
            'synonyms' => [fake()->word()],
        ];
    }
}
