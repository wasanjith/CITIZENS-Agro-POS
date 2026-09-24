<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveCategoryRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Category::class);

        return view('catalog.categories.index', [
            'categories' => self::tree(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Category::class);

        return view('catalog.categories.form', [
            'category' => new Category(['is_active' => true, 'sort_order' => 0, 'parent_id' => request()->integer('parent_id') ?: null]),
            'parents' => self::options(),
        ]);
    }

    public function store(SaveCategoryRequest $request): RedirectResponse
    {
        $category = Category::create($request->validated());

        return redirect()->route('catalog.categories.index')->with('success', "Category {$category->name} created.");
    }

    public function edit(Category $category): View
    {
        $this->authorize('update', $category);

        $excluded = $category->descendantIdsAndSelf();

        return view('catalog.categories.form', [
            'category' => $category,
            'parents' => array_diff_key(self::options(), array_flip($excluded)),
        ]);
    }

    public function update(SaveCategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()->route('catalog.categories.index')->with('success', "Category {$category->name} updated.");
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        if ($category->children()->exists() || $category->products()->withTrashed()->exists()) {
            return back()->with('error', "{$category->name} still has sub-categories or products. Move them first, or mark the category inactive.");
        }

        $category->delete();

        return redirect()->route('catalog.categories.index')->with('success', "Category {$category->name} deleted.");
    }

    /**
     * All categories in tree order, each with a `depth` attribute and product count.
     *
     * @return list<Category>
     */
    public static function tree(bool $activeOnly = false): array
    {
        $all = Category::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Category $category) => $category->parent_id ?? 0);

        $flat = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$flat, $all): void {
            /** @var Collection<int, Category> $children */
            $children = $all->get($parentId, new Collection);

            foreach ($children as $category) {
                $category->setAttribute('depth', $depth);
                $flat[] = $category;

                if ($depth < 10) {
                    $walk($category->id, $depth + 1);
                }
            }
        };
        $walk(0, 0);

        return $flat;
    }

    /**
     * Select options in tree order: [id => "Bicycle Parts › Tyres"].
     *
     * @return array<int, string>
     */
    public static function options(bool $activeOnly = false): array
    {
        $options = [];
        $names = [];

        foreach (self::tree($activeOnly) as $category) {
            $names[$category->getAttribute('depth')] = $category->name;
            $options[$category->id] = implode(' › ', array_slice($names, 0, $category->getAttribute('depth') + 1));
        }

        return $options;
    }
}
