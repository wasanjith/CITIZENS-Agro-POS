<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ?from=&to= date range for books and reports; this month by default.
 */
trait ChoosesPeriod
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function period(Request $request): array
    {
        $from = $this->dateInput($request, 'from') ?? today()->startOfMonth();
        $to = $this->dateInput($request, 'to') ?? today();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    protected function dateInput(Request $request, string $key): ?Carbon
    {
        $value = $request->query($key);

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)?->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
