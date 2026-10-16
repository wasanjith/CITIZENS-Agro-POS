<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Models\SupplierDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Remove a document (all its pages) from the supplier's file. The removal is kept in the audit log.
 */
class RemoveSupplierDocumentAction
{
    public function handle(SupplierDocument $document, User $user): void
    {
        $document->loadMissing(['files', 'supplier']);
        $paths = $document->files->pluck('path')->all();

        $document->delete();
        Storage::disk('local')->delete($paths);

        activity()->performedOn($document->supplier)->causedBy($user)->event('document_removed')
            ->withProperties([
                'type' => $document->type->value,
                'document_date' => $document->document_date->toDateString(),
                'reference' => $document->reference,
                'files' => $paths,
            ])
            ->log($document->type->label().' removed from the supplier file');
    }
}
