<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptInvoice;
use App\Domain\Purchasing\Models\Supplier;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    $this->seed([CatalogSeeder::class, DocumentSequenceSeeder::class]);
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->manager = userWithRole(Role::Manager);
    $this->staff = userWithRole(Role::SalesStaff);
    $this->supplier = Supplier::factory()->create(['name' => 'CIC Agri Businesses']);
    $this->urea = createUrea();
});

/**
 * A draft goods receipt without a purchase order (a delivery that came without one).
 *
 * @param  array<string, mixed>  $extra
 */
function draftGrnWithInvoices(array $extra = []): GoodsReceipt
{
    $test = test();

    $test->actingAs($test->manager)->post(route('purchasing.goods-receipts.store'), [
        'supplier_id' => $test->supplier->id,
        'supplier_invoice_no' => 'CIC-4411',
        'received_at' => now()->subMinute()->format('Y-m-d H:i'),
        'lines' => [[
            'product_id' => $test->urea->id,
            'unit_id' => unitId('bag'),
            'qty' => '2',
            'unit_cost' => '8500',
        ]],
        'action' => 'save',
        ...$extra,
    ])->assertSessionHasNoErrors();

    return GoodsReceipt::query()->latest('id')->firstOrFail();
}

test('invoice photos taken while receiving goods are saved in the supplier\'s folder', function () {
    $receipt = draftGrnWithInvoices([
        'invoice_files' => [
            UploadedFile::fake()->image('IMG_2041.jpg', 1200, 1600),
            UploadedFile::fake()->create('invoice-page-2.pdf', 300, 'application/pdf'),
        ],
    ]);

    $invoices = $receipt->invoices()->get();
    $prefix = 'supplier-invoices/cic-agri-businesses/'.$receipt->received_at->format('Y-m-d').'_'.$receipt->number;

    expect($invoices)->toHaveCount(2)
        ->and($invoices[0]->path)->toBe("{$prefix}_1.jpg")
        ->and($invoices[0]->original_name)->toBe('IMG_2041.jpg')
        ->and($invoices[0]->mime_type)->toBe('image/jpeg')
        ->and($invoices[0]->uploaded_by)->toBe($this->manager->id)
        ->and($invoices[1]->path)->toBe("{$prefix}_2.pdf");

    Storage::disk('local')->assertExists([$invoices[0]->path, $invoices[1]->path]);

    expect(Activity::query()->where('event', 'invoice_attached')->where('subject_id', $receipt->id)->exists())->toBeTrue();
});

test('a posted delivery can get its invoice photo later from the GRN page', function () {
    $receipt = directGrn($this->manager, $this->supplier, $this->urea, '3', '8500');

    $this->actingAs($this->manager)
        ->from(route('purchasing.goods-receipts.show', $receipt))
        ->post(route('purchasing.goods-receipts.invoices.store', $receipt), [
            'invoice_files' => [UploadedFile::fake()->image('photo.png', 800, 1000)],
        ])
        ->assertRedirect(route('purchasing.goods-receipts.show', $receipt))
        ->assertSessionHas('success', 'Invoice photo saved.');

    $invoice = $receipt->invoices()->sole();

    expect($invoice->path)->toStartWith('supplier-invoices/cic-agri-businesses/')->toEndWith('_1.png');
    Storage::disk('local')->assertExists($invoice->path);

    $this->get(route('purchasing.goods-receipts.show', $receipt))
        ->assertOk()
        ->assertSee(route('purchasing.goods-receipts.invoices.show', [$receipt, $invoice]))
        ->assertSee('Take photo');
});

test('file names keep counting after earlier photos', function () {
    $receipt = draftGrnWithInvoices(['invoice_files' => [UploadedFile::fake()->image('a.jpg')]]);

    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.invoices.store', $receipt), [
        'invoice_files' => [UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
    ])->assertSessionHasNoErrors();

    expect($receipt->invoices()->pluck('path')->map(fn ($path) => (string) str($path)->afterLast('_'))->all())
        ->toBe(['1.jpg', '2.jpg', '3.jpg']);
});

test('a supplier with a Sinhala name gets a folder named by its id', function () {
    $supplier = Supplier::factory()->create(['name' => 'ගොවි සේවා']);

    expect($supplier->invoiceFolder())->toBe("supplier-invoices/supplier-{$supplier->id}");
});

test('only photos and PDFs are accepted, and a photo is required', function () {
    $receipt = draftGrnWithInvoices();

    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.invoices.store', $receipt), [
        'invoice_files' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
    ])->assertSessionHasErrors('invoice_files.0');

    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.invoices.store', $receipt), [])
        ->assertSessionHasErrors('invoice_files');

    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.invoices.store', $receipt), [
        'invoice_files' => [UploadedFile::fake()->image('huge.jpg')->size(GoodsReceiptInvoice::MAX_KB + 1)],
    ])->assertSessionHasErrors('invoice_files.0');

    expect($receipt->invoices()->count())->toBe(0);
});

test('a bad invoice file on the GRN form saves nothing', function () {
    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.store'), [
        'supplier_id' => $this->supplier->id,
        'received_at' => now()->subMinute()->format('Y-m-d H:i'),
        'lines' => [['product_id' => $this->urea->id, 'unit_id' => unitId('bag'), 'qty' => '2', 'unit_cost' => '8500']],
        'invoice_files' => [UploadedFile::fake()->create('notes.txt', 5, 'text/plain')],
        'action' => 'save',
    ])->assertSessionHasErrors('invoice_files.0');

    expect(GoodsReceipt::query()->count())->toBe(0);
});

test('sales staff cannot attach or open invoice photos', function () {
    $receipt = draftGrnWithInvoices(['invoice_files' => [UploadedFile::fake()->image('a.jpg')]]);
    $invoice = $receipt->invoices()->sole();

    $this->actingAs($this->staff)->post(route('purchasing.goods-receipts.invoices.store', $receipt), [
        'invoice_files' => [UploadedFile::fake()->image('b.jpg')],
    ])->assertForbidden();

    $this->actingAs($this->staff)->get(route('purchasing.goods-receipts.invoices.show', [$receipt, $invoice]))->assertForbidden();
});

test('the owner and manager can open a photo, only through its own GRN', function () {
    $receipt = draftGrnWithInvoices(['invoice_files' => [UploadedFile::fake()->image('a.jpg')]]);
    $other = draftGrnWithInvoices();
    $invoice = $receipt->invoices()->sole();

    $this->actingAs($this->manager)->get(route('purchasing.goods-receipts.invoices.show', [$receipt, $invoice]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    $this->actingAs($this->owner)->get(route('purchasing.goods-receipts.invoices.show', [$other, $invoice]))->assertNotFound();
});

test('the manager may remove a photo from a draft but not from a posted GRN; the owner may', function () {
    $receipt = draftGrnWithInvoices(['invoice_files' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]]);
    [$first, $second] = $receipt->invoices()->get()->all();

    $this->actingAs($this->manager)->delete(route('purchasing.goods-receipts.invoices.destroy', [$receipt, $first]))
        ->assertSessionHas('success', 'Invoice photo removed.');
    Storage::disk('local')->assertMissing($first->path);
    expect(GoodsReceiptInvoice::query()->find($first->id))->toBeNull()
        ->and(Activity::query()->where('event', 'invoice_removed')->exists())->toBeTrue();

    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.post', $receipt))->assertSessionHasNoErrors();
    expect($receipt->refresh()->status)->toBe(GoodsReceiptStatus::Posted);

    $this->actingAs($this->manager)->delete(route('purchasing.goods-receipts.invoices.destroy', [$receipt, $second]))->assertForbidden();
    Storage::disk('local')->assertExists($second->path);

    $this->actingAs($this->owner)->delete(route('purchasing.goods-receipts.invoices.destroy', [$receipt, $second]))->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($second->path);
});

test('a cancelled GRN takes no invoice photos', function () {
    $receipt = draftGrnWithInvoices();
    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.cancel', $receipt))->assertSessionHasNoErrors();

    $this->actingAs($this->manager)->post(route('purchasing.goods-receipts.invoices.store', $receipt), [
        'invoice_files' => [UploadedFile::fake()->image('a.jpg')],
    ])->assertForbidden();

    $this->actingAs($this->owner)->post(route('purchasing.goods-receipts.invoices.store', $receipt), [
        'invoice_files' => [UploadedFile::fake()->image('a.jpg')],
    ])->assertSessionHasErrors('invoice_files');

    expect($receipt->invoices()->count())->toBe(0);
});

test('the GRN list shows how many invoice photos each receipt has', function () {
    draftGrnWithInvoices(['invoice_files' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]]);

    $this->actingAs($this->manager)->get(route('purchasing.goods-receipts.index'))
        ->assertOk()
        ->assertSee('2 invoice photos');
});
