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
            ['type' => 'SRN', 'prefix' => 'SRN-{Y}-', 'padding' => 5, 'reset_period' => SequenceResetPeriod::Yearly],
            ['type' => 'ADJ', 'prefix' => 'ADJ-{Y}-', 'padding' => 5, 'reset_period' => SequenceResetPeriod::Yearly],
            ['type' => 'STK', 'prefix' => 'STK-{Y}-', 'padding' => 4, 'reset_period' => SequenceResetPeriod::Yearly],
            ['type' => 'CUST', 'prefix' => 'C-', 'padding' => 5, 'reset_period' => SequenceResetPeriod::Never],
            ['type' => 'RCP', 'prefix' => 'RCP-{Y}-', 'padding' => 5, 'reset_period' => SequenceResetPeriod::Yearly],
            ['type' => 'RET', 'prefix' => 'RET-{Y}-', 'padding' => 5, 'reset_period' => SequenceResetPeriod::Yearly],
            ['type' => 'QUO', 'prefix' => 'QT-{Y}-', 'padding' => 5, 'reset_period' => SequenceResetPeriod::Yearly],
            ['type' => 'JE', 'prefix' => 'JE-{Y}-', 'padding' => 6, 'reset_period' => SequenceResetPeriod::Yearly],
            ['type' => 'EXP', 'prefix' => 'EXP-{Y}-', 'padding' => 5, 'reset_period' => SequenceResetPeriod::Yearly],
            ['type' => 'SPAY', 'prefix' => 'SP-{Y}-', 'padding' => 5, 'reset_period' => SequenceResetPeriod::Yearly],
        ];

        foreach ($sequences as $sequence) {
            DocumentSequence::firstOrCreate(['type' => $sequence['type']], $sequence + ['next_number' => 1]);
        }
    }
}
