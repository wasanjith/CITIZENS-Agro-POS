<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\Inventory\Enums\AdjustmentStatus;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Notifications\StockAdjustmentPendingApproval;
use App\Domain\Inventory\Services\StockService;
use App\Domain\System\Services\Settings;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed([CatalogSeeder::class, DocumentSequenceSeeder::class]);
    app(Settings::class)->setGroup('inventory', ['adjustment_approval_limit' => 10000]);
    $this->owner = userWithRole(Role::SuperAdmin);
    $this->manager = userWithRole(Role::Manager);
    $this->staff = userWithRole(Role::SalesStaff);
    $this->urea = createUrea();
    $this->stock = app(StockService::class);
    $this->batch = $this->stock->receive($this->urea, null, '500', '170');
});

afterEach(function () {
    expectStockMatchesLedger();
});

function adjustmentPayload(string $qty, string $reason = 'damage', array $line = []): array
{
    return [
        'reason' => $reason,
        'note' => 'Wet bags in the store',
        'lines' => [['product_id' => test()->urea->id, 'variant_id' => '', 'batch_id' => '', 'qty' => $qty, ...$line]],
    ];
}

test('a small adjustment by the manager is posted at once', function () {
    // 50 kg × 170 = 8,500: under the limit.
    $this->actingAs($this->manager)->post(route('inventory.adjustments.store'), adjustmentPayload('-50'))->assertSessionHasNoErrors();

    $adjustment = StockAdjustment::firstOrFail();

    expect($adjustment->status)->toBe(AdjustmentStatus::Approved)
        ->and($adjustment->total_value)->toBe('8500.00')
        ->and($adjustment->number)->toStartWith('ADJ-')
        ->and((string) $this->stock->available($this->urea))->toBe('450.000')
        ->and(StockMovement::where('type', MovementType::Damage)->value('qty'))->toBe('-50.000');
});

test('above the limit it waits for the owner, who approves it', function () {
    Notification::fake();

    // 100 kg × 170 = 17,000: over the limit.
    $this->actingAs($this->manager)->post(route('inventory.adjustments.store'), adjustmentPayload('-100', 'lost'))->assertSessionHasNoErrors();
    $adjustment = StockAdjustment::firstOrFail();

    expect($adjustment->status)->toBe(AdjustmentStatus::PendingApproval)
        ->and((string) $this->stock->available($this->urea))->toBe('500.000');
    Notification::assertSentTo($this->owner, StockAdjustmentPendingApproval::class);

    $this->actingAs($this->manager)->post(route('inventory.adjustments.approve', $adjustment))->assertForbidden();
    $this->actingAs($this->owner)->post(route('inventory.adjustments.approve', $adjustment))->assertSessionHasNoErrors();

    expect($adjustment->refresh()->status)->toBe(AdjustmentStatus::Approved)
        ->and($adjustment->approved_by)->toBe($this->owner->id)
        ->and((string) $this->stock->available($this->urea))->toBe('400.000')
        ->and(StockMovement::where('type', MovementType::AdjustOut)->value('qty'))->toBe('-100.000');
});

test('a rejected adjustment leaves stock alone', function () {
    $this->actingAs($this->manager)->post(route('inventory.adjustments.store'), adjustmentPayload('-100'));
    $adjustment = StockAdjustment::firstOrFail();

    $this->actingAs($this->owner)
        ->post(route('inventory.adjustments.reject', $adjustment), ['rejection_reason' => 'Recount first'])
        ->assertSessionHasNoErrors();

    expect($adjustment->refresh()->status)->toBe(AdjustmentStatus::Rejected)
        ->and($adjustment->rejection_reason)->toBe('Recount first')
        ->and((string) $this->stock->available($this->urea))->toBe('500.000');
});

test('the owner posts any value straight away; found goods are added', function () {
    $this->actingAs($this->owner)->post(route('inventory.adjustments.store'), adjustmentPayload('200', 'found'))->assertSessionHasNoErrors();

    expect(StockAdjustment::firstOrFail()->status)->toBe(AdjustmentStatus::Approved)
        ->and((string) $this->stock->available($this->urea))->toBe('700.000')
        ->and(StockMovement::where('type', MovementType::AdjustIn)->value('qty'))->toBe('200.000');
});

test('taking out more than there is fails and nothing is posted', function () {
    $this->actingAs($this->owner)->post(route('inventory.adjustments.store'), adjustmentPayload('-600'))->assertSessionHasErrors('stock');

    expect(StockAdjustment::count())->toBe(0)
        ->and((string) $this->stock->available($this->urea))->toBe('500.000');
});

test('a batch of another product is refused', function () {
    $other = createProduct();
    $otherBatch = $this->stock->receive($other, null, '5', '10');

    $this->actingAs($this->owner)
        ->post(route('inventory.adjustments.store'), adjustmentPayload('-1', line: ['batch_id' => $otherBatch->id]))
        ->assertSessionHasErrors('lines.0.batch_id');
});

test('sales staff cannot adjust stock', function () {
    $this->actingAs($this->staff)->get(route('inventory.adjustments.create'))->assertForbidden();
    $this->actingAs($this->staff)->post(route('inventory.adjustments.store'), adjustmentPayload('-1'))->assertForbidden();
});

test('adjustment pages render, including a write-off started from a batch', function () {
    $this->actingAs($this->manager)->post(route('inventory.adjustments.store'), adjustmentPayload('-5'));

    $this->actingAs($this->owner)->get(route('inventory.adjustments.index'))->assertOk()->assertSee('ADJ-');
    $this->actingAs($this->owner)->get(route('inventory.adjustments.show', StockAdjustment::firstOrFail()))->assertOk()->assertSee('Urea 50kg');
    $this->actingAs($this->owner)->get(route('inventory.adjustments.create', ['batch' => $this->batch->id]))->assertOk()->assertSee('Urea 50kg');
});
