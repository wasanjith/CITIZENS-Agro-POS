<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Purchasing\Enums\SupplierDocumentType;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierDocument;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->manager = userWithRole(Role::Manager);
    $this->staff = userWithRole(Role::SalesStaff);
    $this->supplier = Supplier::factory()->create(['name' => 'CIC Agri Businesses']);
});

/**
 * @param  array<string, mixed>  $extra
 */
function addSupplierDocument(mixed $user, Supplier $supplier, array $extra = []): SupplierDocument
{
    test()->actingAs($user)->post(route('purchasing.invoices.store', $supplier), [
        'type' => 'deposit_slip',
        'document_date' => '2026-10-14',
        'reference' => 'BOC 77812',
        'amount' => '125000.50',
        'note' => 'Paid for October fertilizer',
        'document_files' => [UploadedFile::fake()->image('slip.jpg', 900, 1200)],
        ...$extra,
    ])->assertSessionHasNoErrors();

    return SupplierDocument::query()->latest('id')->firstOrFail();
}

test('the Invoices page shows every supplier as a folder', function () {
    Supplier::factory()->create(['name' => 'Baur & Co']);
    Supplier::factory()->create(['name' => 'Old Agent', 'is_active' => false]);
    addSupplierDocument($this->manager, $this->supplier);

    $this->actingAs($this->owner)->get(route('purchasing.invoices.index'))
        ->assertOk()
        ->assertSeeInOrder(['Baur &amp; Co', 'CIC Agri Businesses'], false)
        ->assertSee('1 deposit slip')
        ->assertDontSee('Old Agent');
});

test('a deposit slip with two pages is saved in the supplier\'s deposit-slips folder', function () {
    $this->actingAs($this->manager)
        ->post(route('purchasing.invoices.store', $this->supplier), [
            'type' => 'deposit_slip',
            'document_date' => '2026-10-14',
            'reference' => 'BOC 77812',
            'amount' => '125000.50',
            'document_files' => [
                UploadedFile::fake()->image('IMG_1.jpg', 900, 1200),
                UploadedFile::fake()->create('back.pdf', 200, 'application/pdf'),
            ],
        ])
        ->assertRedirect(route('purchasing.invoices.show', $this->supplier))
        ->assertSessionHas('success', 'Bank deposit slip saved in the CIC Agri Businesses folder.');

    $document = SupplierDocument::query()->with('files')->sole();

    expect($document->type)->toBe(SupplierDocumentType::DepositSlip)
        ->and($document->amount)->toBe('125000.50')
        ->and($document->uploaded_by)->toBe($this->manager->id)
        ->and($document->files->pluck('path')->all())->toBe([
            'supplier-invoices/cic-agri-businesses/deposit-slips/2026-10-14_BOC-77812_1.jpg',
            'supplier-invoices/cic-agri-businesses/deposit-slips/2026-10-14_BOC-77812_2.pdf',
        ]);

    Storage::disk('local')->assertExists($document->files->pluck('path')->all());
    expect(Activity::query()->where('event', 'document_added')->where('subject_id', $this->supplier->id)->exists())->toBeTrue();
});

test('the folder lists its papers with the goods receipt invoice photos and filters by type', function () {
    $this->seed([CatalogSeeder::class, DocumentSequenceSeeder::class]);
    $receipt = directGrn($this->manager, $this->supplier, createUrea(), '2', '8500');
    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.invoices.store', $receipt), [
        'invoice_files' => [UploadedFile::fake()->image('bill.jpg', 800, 1000)],
    ])->assertSessionHasNoErrors();

    addSupplierDocument($this->manager, $this->supplier, ['reference' => 'SLIP-1']);
    addSupplierDocument($this->manager, $this->supplier, ['type' => 'receipt', 'reference' => 'RCPT-9']);

    $this->actingAs($this->owner)->get(route('purchasing.invoices.show', $this->supplier))
        ->assertOk()
        ->assertSee('SLIP-1')
        ->assertSee('RCPT-9')
        ->assertSee($receipt->number);

    $this->get(route('purchasing.invoices.show', [$this->supplier, 'type' => 'receipt']))
        ->assertOk()
        ->assertSee('RCPT-9')
        ->assertDontSee('SLIP-1')
        ->assertDontSee($receipt->number);

    $this->get(route('purchasing.invoices.show', [$this->supplier, 'search' => 'slip-1']))
        ->assertOk()
        ->assertSee('SLIP-1')
        ->assertDontSee('RCPT-9');
});

test('a document page can be opened only through its own supplier', function () {
    $document = addSupplierDocument($this->manager, $this->supplier);
    $file = $document->files()->sole();
    $other = Supplier::factory()->create();

    $this->actingAs($this->manager)->get(route('purchasing.invoices.file', [$this->supplier, $file]))->assertOk();
    $this->get(route('purchasing.invoices.file', [$other, $file]))->assertNotFound();
});

test('sales staff cannot open or add to supplier files', function () {
    $document = addSupplierDocument($this->manager, $this->supplier);

    $this->actingAs($this->staff)->get(route('purchasing.invoices.index'))->assertForbidden();
    $this->get(route('purchasing.invoices.show', $this->supplier))->assertForbidden();
    $this->get(route('purchasing.invoices.file', [$this->supplier, $document->files()->sole()]))->assertForbidden();
    $this->post(route('purchasing.invoices.store', $this->supplier), [
        'type' => 'invoice',
        'document_date' => '2026-10-14',
        'document_files' => [UploadedFile::fake()->image('x.jpg')],
    ])->assertForbidden();
});

test('a document needs a type, a past date and at least one photo or PDF', function () {
    $this->actingAs($this->manager)
        ->post(route('purchasing.invoices.store', $this->supplier), [
            'type' => 'cheque',
            'document_date' => now()->addDay()->toDateString(),
            'document_files' => [UploadedFile::fake()->create('notes.docx', 20)],
        ])
        ->assertSessionHasErrors(['type', 'document_date', 'document_files.0']);

    $this->post(route('purchasing.invoices.store', $this->supplier), ['type' => 'invoice', 'document_date' => '2026-10-14'])
        ->assertSessionHasErrors(['document_files' => 'Take a photo of the document or choose a file first.']);

    expect(SupplierDocument::query()->count())->toBe(0);
});

test('the manager can remove only their own document on the same day; the owner can remove any', function () {
    $document = addSupplierDocument($this->manager, $this->supplier);
    $paths = $document->files()->pluck('path')->all();

    $this->travel(1)->day();

    $this->actingAs($this->manager)->delete(route('purchasing.invoices.destroy', [$this->supplier, $document]))->assertForbidden();

    $this->actingAs($this->owner)->delete(route('purchasing.invoices.destroy', [$this->supplier, $document]))
        ->assertSessionHas('success', 'Bank deposit slip removed.');

    expect(SupplierDocument::query()->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($paths);
    expect(Activity::query()->where('event', 'document_removed')->exists())->toBeTrue();
});

test('the manager can remove a wrong photo they added today', function () {
    $document = addSupplierDocument($this->manager, $this->supplier);

    $this->actingAs($this->manager)->delete(route('purchasing.invoices.destroy', [$this->supplier, $document]))
        ->assertSessionHasNoErrors();

    expect(SupplierDocument::query()->exists())->toBeFalse();
});

test('a document cannot be removed through another supplier', function () {
    $document = addSupplierDocument($this->manager, $this->supplier);
    $other = Supplier::factory()->create();

    $this->actingAs($this->owner)->delete(route('purchasing.invoices.destroy', [$other, $document]))->assertNotFound();
    expect(SupplierDocument::query()->exists())->toBeTrue();
});
