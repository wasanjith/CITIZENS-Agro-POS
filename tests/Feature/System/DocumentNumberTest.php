<?php

use App\Domain\System\Enums\SequenceResetPeriod;
use App\Domain\System\Models\DocumentSequence;
use App\Domain\System\Services\DocumentNumber;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 24));
    DocumentSequence::create(['type' => 'SALE', 'prefix' => 'INV-{Y}-', 'padding' => 6, 'reset_period' => SequenceResetPeriod::Yearly]);
});

test('numbers are sequential and formatted', function () {
    $numbers = collect(range(1, 20))->map(fn () => app(DocumentNumber::class)->next('SALE'));

    expect($numbers->first())->toBe('INV-2026-000001')
        ->and($numbers->last())->toBe('INV-2026-000020')
        ->and($numbers->unique())->toHaveCount(20);
});

test('a rolled-back transaction does not leave a gap', function () {
    app(DocumentNumber::class)->next('SALE');

    try {
        DB::transaction(function () {
            app(DocumentNumber::class)->next('SALE');
            throw new RuntimeException('Invoice failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(app(DocumentNumber::class)->next('SALE'))->toBe('INV-2026-000002');
});

test('yearly sequences restart in a new year', function () {
    app(DocumentNumber::class)->next('SALE');
    app(DocumentNumber::class)->next('SALE');

    $this->travelTo(now()->setDate(2027, 1, 1));

    expect(app(DocumentNumber::class)->next('SALE'))->toBe('INV-2027-000001');
});

test('an unknown sequence type fails loudly', function () {
    app(DocumentNumber::class)->next('NOPE');
})->throws(RuntimeException::class);
