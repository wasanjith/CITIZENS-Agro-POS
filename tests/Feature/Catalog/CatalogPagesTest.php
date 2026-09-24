<?php

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SearchSynonym;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Enums\Role;
use Database\Seeders\CatalogSeeder;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
    $this->owner = userWithRole(Role::SuperAdmin);
});

test('every catalog page renders for the owner', function (string $route, array $parameters = []) {
    createUrea();
    Brand::factory()->create();
    SearchSynonym::create(['term' => 'tsp', 'synonyms' => ['triple super phosphate']]);

    $parameters = array_map(fn ($value) => $value instanceof Closure ? $value() : $value, $parameters);

    $this->actingAs($this->owner)->get(route($route, $parameters))->assertOk();
})->with([
    'products' => ['catalog.products.index'],
    'product create' => ['catalog.products.create'],
    'product show' => ['catalog.products.show', ['product' => fn () => Product::firstOrFail()]],
    'product edit' => ['catalog.products.edit', ['product' => fn () => Product::firstOrFail()]],
    'product import' => ['catalog.products.import'],
    'categories' => ['catalog.categories.index'],
    'category create' => ['catalog.categories.create'],
    'category edit' => ['catalog.categories.edit', ['category' => fn () => Category::firstOrFail()]],
    'brands' => ['catalog.brands.index'],
    'brand create' => ['catalog.brands.create'],
    'brand edit' => ['catalog.brands.edit', ['brand' => fn () => Brand::firstOrFail()]],
    'units' => ['catalog.units.index'],
    'unit create' => ['catalog.units.create'],
    'unit edit' => ['catalog.units.edit', ['unit' => fn () => Unit::firstOrFail()]],
    'taxes' => ['catalog.taxes.index'],
    'tax create' => ['catalog.taxes.create'],
    'tax edit' => ['catalog.taxes.edit', ['tax' => fn () => Tax::firstOrFail()]],
    'synonyms' => ['catalog.synonyms.index'],
    'synonym create' => ['catalog.synonyms.create'],
    'synonym edit' => ['catalog.synonyms.edit', ['synonym' => fn () => SearchSynonym::firstOrFail()]],
]);

test('the manager manages the catalogue but not taxes', function () {
    $manager = userWithRole(Role::Manager);

    $this->actingAs($manager)->get(route('catalog.categories.index'))->assertOk();
    $this->actingAs($manager)->get(route('catalog.synonyms.index'))->assertOk();
    $this->actingAs($manager)->get(route('catalog.taxes.index'))->assertForbidden();
});

test('sales staff only see the product list', function () {
    $staff = userWithRole(Role::SalesStaff);

    $this->actingAs($staff)->get(route('catalog.products.index'))->assertOk();

    foreach (['catalog.categories.index', 'catalog.brands.index', 'catalog.units.index', 'catalog.synonyms.index', 'catalog.products.import'] as $route) {
        $this->actingAs($staff)->get(route($route))->assertForbidden();
    }
});

test('categories form a tree and a range may not overlap another', function () {
    $bicycle = Category::firstWhere('name', 'Bicycle Parts');

    $this->actingAs($this->owner)->post(route('catalog.categories.store'), [
        'name' => 'Pedals', 'parent_id' => $bicycle->id, 'sort_order' => 9, 'is_active' => '1',
    ])->assertSessionHasNoErrors();

    $pedals = Category::firstWhere('name', 'Pedals');
    expect($pedals->path())->toBe('Bicycle Parts › Pedals')
        ->and($pedals->codeRange())->toBe([5000, 6999]);

    $this->actingAs($this->owner)->post(route('catalog.categories.store'), [
        'name' => 'Irrigation', 'code_from' => 1500, 'code_to' => 2500, 'sort_order' => 0, 'is_active' => '1',
    ])->assertSessionHasErrors('code_from');

    $this->actingAs($this->owner)->put(route('catalog.categories.update', $bicycle), [
        'name' => 'Bicycle Parts', 'parent_id' => $pedals->id, 'sort_order' => 0, 'is_active' => '1',
    ])->assertSessionHasErrors('parent_id');
});

test('a category with products cannot be deleted', function () {
    createProduct(['category' => 'Tools']);
    $tools = Category::firstWhere('name', 'Tools');

    $this->actingAs($this->owner)->delete(route('catalog.categories.destroy', $tools))->assertSessionHas('error');

    expect($tools->fresh()->trashed())->toBeFalse();
});

test('synonyms are saved as a clean word list', function () {
    $this->actingAs($this->owner)->post(route('catalog.synonyms.store'), [
        'term' => ' TSP ',
        'synonyms' => "Triple Super Phosphate,  t.s.p\ntriple super phosphate",
    ])->assertSessionHasNoErrors();

    expect(SearchSynonym::firstWhere('term', 'tsp')->synonyms)->toBe(['triple super phosphate', 't.s.p'])
        ->and(SearchSynonym::toMeilisearch()['t.s.p'])->toBe(['tsp', 'triple super phosphate']);
});
