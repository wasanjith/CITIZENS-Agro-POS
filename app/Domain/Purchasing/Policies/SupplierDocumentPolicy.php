<?php

namespace App\Domain\Purchasing\Policies;

use App\Domain\Purchasing\Models\SupplierDocument;
use App\Models\User;

/**
 * Supplier files (Purchasing → Invoices): Owner and Manager.
 */
class SupplierDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchasing.suppliers.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.suppliers.manage');
    }

    /**
     * The Super Admin may remove any document; others only what they added themselves today
     * (a wrong or blurred photo noticed straight away).
     */
    public function delete(User $user, SupplierDocument $document): bool
    {
        if (! $user->can('purchasing.suppliers.manage')) {
            return false;
        }

        return $user->isSuperAdmin()
            || ($document->uploaded_by === $user->id && $document->created_at?->isToday());
    }
}
