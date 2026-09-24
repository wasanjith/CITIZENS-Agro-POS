<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\Models\SearchSynonym;
use App\Http\Controllers\Concerns\HasListQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SearchSynonymController extends Controller
{
    use HasListQuery;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SearchSynonym::class);

        return view('catalog.synonyms.index', [
            'synonyms' => $this->applyListQuery(
                SearchSynonym::query(),
                $request,
                searchable: ['term', 'synonyms'],
                sortable: ['term', 'updated_at'],
                defaultSort: 'term',
                defaultDirection: 'asc',
            )->paginate(50)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', SearchSynonym::class);

        return view('catalog.synonyms.form', ['synonym' => new SearchSynonym(['synonyms' => []])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SearchSynonym::class);

        $synonym = SearchSynonym::create($this->validated($request));

        return redirect()->route('catalog.synonyms.index')->with('success', "Synonyms for \"{$synonym->term}\" saved. Search picks them up within a minute.");
    }

    public function edit(SearchSynonym $synonym): View
    {
        $this->authorize('update', $synonym);

        return view('catalog.synonyms.form', ['synonym' => $synonym]);
    }

    public function update(Request $request, SearchSynonym $synonym): RedirectResponse
    {
        $this->authorize('update', $synonym);

        $synonym->update($this->validated($request, $synonym));

        return redirect()->route('catalog.synonyms.index')->with('success', "Synonyms for \"{$synonym->term}\" saved. Search picks them up within a minute.");
    }

    public function destroy(SearchSynonym $synonym): RedirectResponse
    {
        $this->authorize('delete', $synonym);

        $synonym->delete();

        return redirect()->route('catalog.synonyms.index')->with('success', "Synonyms for \"{$synonym->term}\" deleted.");
    }

    /**
     * "triple super phosphate, t.s.p" → ['triple super phosphate', 't.s.p'].
     *
     * @return array{term: string, synonyms: list<string>}
     */
    private function validated(Request $request, ?SearchSynonym $synonym = null): array
    {
        $request->merge([
            'term' => mb_strtolower(trim((string) $request->input('term'))),
            'synonym_list' => array_values(array_unique(array_filter(array_map(
                fn ($word) => mb_strtolower(trim($word)),
                preg_split('/[,\n]+/', (string) $request->input('synonyms')) ?: [],
            )))),
        ]);

        $data = $request->validate([
            'term' => ['required', 'string', 'max:100', Rule::unique('search_synonyms', 'term')->ignore($synonym?->id)],
            'synonym_list' => ['required', 'array', 'min:1', 'max:20'],
            'synonym_list.*' => ['string', 'max:100', 'different:term'],
        ], [
            'synonym_list.required' => 'Enter at least one synonym.',
        ]);

        return ['term' => $data['term'], 'synonyms' => $data['synonym_list']];
    }
}
