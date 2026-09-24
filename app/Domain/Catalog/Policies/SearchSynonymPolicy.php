<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\SearchSynonym;
use App\Models\User;

class SearchSynonymPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.synonyms.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.synonyms.manage');
    }

    public function update(User $user, SearchSynonym $searchSynonym): bool
    {
        return $user->can('catalog.synonyms.manage');
    }

    public function delete(User $user, SearchSynonym $searchSynonym): bool
    {
        return $user->can('catalog.synonyms.manage');
    }
}
