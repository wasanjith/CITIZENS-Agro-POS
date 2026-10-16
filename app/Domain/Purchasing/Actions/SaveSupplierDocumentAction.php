<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\SupplierDocumentType;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Add an invoice, bank deposit slip, receipt or other paper to the supplier's file. The pages
 * go into a sub-folder of the supplier's folder, named by date and reference:
 * supplier-invoices/cic-agri-businesses/deposit-slips/2026-10-16_BOC-77812_1.jpg
 */
class SaveSupplierDocumentAction
{
    /**
     * @param  array{type: string, document_date: string, reference?: string|null, amount?: string|null, note?: string|null}  $data
     * @param  array<int, UploadedFile>  $files
     */
    public function handle(Supplier $supplier, array $data, array $files, User $user): SupplierDocument
    {
        $type = SupplierDocumentType::from($data['type']);
        $date = Carbon::parse($data['document_date']);
        $reference = trim((string) ($data['reference'] ?? '')) ?: null;

        $disk = Storage::disk('local');
        $folder = $supplier->invoiceFolder().'/'.$type->folder();
        $safeReference = mb_substr(trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $reference), '-'), 0, 60);
        $prefix = $date->format('Y-m-d').'_'.($safeReference ?: $type->folder());
        $stored = [];

        try {
            return DB::transaction(function () use ($supplier, $type, $date, $reference, $data, $files, $user, $disk, $folder, $prefix, &$stored): SupplierDocument {
                $document = $supplier->documents()->create([
                    'type' => $type,
                    'document_date' => $date->toDateString(),
                    'reference' => $reference,
                    'amount' => ($data['amount'] ?? '') !== '' ? $data['amount'] : null,
                    'note' => trim((string) ($data['note'] ?? '')) ?: null,
                    'uploaded_by' => $user->id,
                ]);

                $sequence = 0;

                foreach ($files as $file) {
                    $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
                    $extension = $extension === 'jpeg' ? 'jpg' : $extension;

                    do {
                        $name = $prefix.'_'.(++$sequence).'.'.$extension;
                    } while ($disk->exists("{$folder}/{$name}"));

                    $path = $file->storeAs($folder, $name, 'local');

                    if ($path === false) {
                        throw ValidationException::withMessages(['document_files' => "Could not save {$file->getClientOriginalName()}. Is the server disk full?"]);
                    }

                    $stored[] = $path;

                    $document->files()->create([
                        'path' => $path,
                        'original_name' => mb_substr($file->getClientOriginalName(), 0, 255) ?: null,
                        'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                        'size' => (int) $file->getSize(),
                    ]);
                }

                activity()->performedOn($supplier)->causedBy($user)->event('document_added')
                    ->withProperties(['document_id' => $document->id, 'type' => $type->value, 'reference' => $reference, 'files' => $stored])
                    ->log($type->label().' added to the supplier file');

                return $document;
            });
        } catch (\Throwable $e) {
            // Do not leave orphan files behind when the save fails half way.
            $disk->delete($stored);

            throw $e;
        }
    }
}
