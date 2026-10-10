<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

/**
 * @return array<string, string> command => cron expression
 */
function backupSchedule(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'backup:'))
        ->mapWithKeys(fn (Event $event) => [trim(str($event->command)->after('artisan')->replace(["'", '"'], '')) => $event->expression])
        ->all();
}

test('backups are scheduled: hourly database, nightly full, cleanup and health check', function () {
    expect(backupSchedule())->toBe([
        'backup:run --only-db' => '0 * * * *',
        'backup:run' => '0 22 * * *',
        'backup:clean' => '0 1 * * *',
        'backup:monitor' => '20 7 * * *',
    ]);
});

test('the full backup runs after the last hourly one, so it is the day\'s backup that is kept', function () {
    // between() takes "now" when the schedule is registered (schedule:run boots fresh
    // every minute), so register it again at each moment.
    $hourlyRunsAt = function (int $hour): bool {
        $this->travelTo(today()->setTime($hour, 0));
        ScheduleFacade::swap(new Schedule);
        require base_path('routes/console.php');

        // As schedule:run decides: the cron expression is due and the filters (between) pass.
        return collect(app(Schedule::class)->dueEvents(app()))
            ->contains(fn (Event $event) => str_contains((string) $event->command, 'backup:run --only-db') && $event->filtersPass(app()));
    };

    expect($hourlyRunsAt(7))->toBeFalse()
        ->and($hourlyRunsAt(8))->toBeTrue()
        ->and($hourlyRunsAt(20))->toBeTrue()
        ->and($hourlyRunsAt(21))->toBeFalse()
        ->and($hourlyRunsAt(22))->toBeFalse();
});

test('backups go to the backup disk, include the database and uploads, and skip temporary files', function () {
    expect(config('backup.backup.destination.disks'))->toBe(['backup'])
        ->and(config('filesystems.disks.backup.driver'))->toBe('local')
        ->and(config('backup.backup.source.databases'))->toBe(['mysql'])
        ->and(config('backup.backup.source.files.include'))->toContain(storage_path('app/private'), base_path('.env'))
        ->and(config('backup.backup.source.files.exclude'))->toContain(storage_path('app/private/exports'), storage_path('app/private/imports'))
        ->and(config('database.connections.mysql.dump'))->toContain('use_single_transaction');
});
