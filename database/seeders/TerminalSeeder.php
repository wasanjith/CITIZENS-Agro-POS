<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\TerminalType;
use App\Domain\Identity\Models\Printer;
use App\Domain\Identity\Models\Terminal;
use Illuminate\Database\Seeder;

/**
 * The shop's four terminals, each with its own USB thermal printer.
 */
class TerminalSeeder extends Seeder
{
    public function run(): void
    {
        $terminals = [
            ['code' => 'MAIN', 'name' => 'Main Cashier', 'type' => TerminalType::MainCashier, 'counter_no' => null, 'printer' => 'Printer #0 (Main)', 'drawer' => true],
            ['code' => 'C1', 'name' => 'Counter 1', 'type' => TerminalType::Counter, 'counter_no' => 1, 'printer' => 'Printer #1 (Counter 1)', 'drawer' => false],
            ['code' => 'C2', 'name' => 'Counter 2', 'type' => TerminalType::Counter, 'counter_no' => 2, 'printer' => 'Printer #2 (Counter 2)', 'drawer' => false],
            ['code' => 'C3', 'name' => 'Counter 3', 'type' => TerminalType::Counter, 'counter_no' => 3, 'printer' => 'Printer #3 (Counter 3)', 'drawer' => false],
        ];

        foreach ($terminals as $definition) {
            $terminal = Terminal::firstOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'counter_no' => $definition['counter_no'],
                    'is_active' => true,
                ],
            );

            if ($terminal->printer === null) {
                Printer::create([
                    'name' => $definition['printer'],
                    'terminal_id' => $terminal->id,
                    'paper_width_mm' => 80,
                    'dpi' => 203,
                    'has_cash_drawer' => $definition['drawer'],
                    'is_active' => true,
                ]);
            }
        }
    }
}
