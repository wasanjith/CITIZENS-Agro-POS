<?php

namespace Database\Seeders;

use App\Domain\System\Enums\SequenceResetPeriod;
use App\Domain\System\Models\DocumentSequence;
use Illuminate\Database\Seeder;

class DocumentSequenceSeeder extends Seeder
{
    public function run(): void
    {
        $sequences = [
            ['type' => 'SALE', 'prefix' => 'INV-{Y}-', 'padding' => 6, 'reset_period' => SequenceResetPeriod::Yearly],
            ['type' => 'PO', 'prefix' => 'PO-{Y}-', 'padding' => 5, 'reset_period' => SequenceResetPeriod::Yearly],
            ['type' => 'GRN', 'prefix' => 'GRN-{Y}-', 'padding' => 5, 'reset_period' => SequenceResetPeriod::Yearly],
        ];

        foreach ($sequences as $sequence) {
            DocumentSequence::firstOrCreate(['type' => $sequence['type']], $sequence + ['next_number' => 1]);
        }
    }
}
