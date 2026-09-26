<?php

use App\Domain\CashDrawer\Jobs\ProcessExpiredDelegationsJob;
use App\Domain\Customers\Jobs\OverdueCreditReminderJob;
use App\Domain\Finance\Jobs\ChequesDueReminderJob;
use App\Domain\Finance\Services\JournalBackfill;
use App\Domain\HR\Jobs\MissingClockOutAlertJob;
use App\Domain\Identity\Actions\SaveUserAction;
use App\Domain\Identity\Enums\Role;
use App\Domain\Inventory\Actions\PostOpeningStockAction;
use App\Domain\Inventory\Jobs\LowStockAndExpiryAlertJob;
use App\Domain\Sales\Jobs\PruneCounterEventsJob;
use App\Domain\Sales\Jobs\RecalculateSalesVelocityJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

Artisan::command('pos:create-owner', function (SaveUserAction $saveUser) {
    $name = text('Owner full name', required: true);
    $username = text('Username', default: 'owner', required: true);
    $userPassword = password('Password (min 8 characters)', required: true, validate: fn (string $value) => strlen($value) < 8 ? 'At least 8 characters.' : null);
    $pin = password('PIN for terminals (4-6 digits, optional)', validate: fn (string $value) => $value === '' || preg_match('/^\d{4,6}$/', $value) ? null : '4 to 6 digits.');

    $validator = Validator::make(['username' => mb_strtolower($username)], ['username' => ['alpha_dash:ascii', 'unique:users,username']]);

    if ($validator->fails()) {
        $this->error($validator->errors()->first('username'));

        return 1;
    }

    $user = $saveUser->handle([
        'name' => $name,
        'username' => $username,
        'role' => Role::SuperAdmin->value,
        'password' => $userPassword,
        'pin' => $pin ?: null,
        'is_active' => true,
    ]);

    $this->info("Super Admin {$user->username} created.");
})->purpose('Create the first Super Admin (shop owner) account');

Artisan::command('inventory:post-opening-stock', function (PostOpeningStockAction $postOpeningStock) {
    $count = $postOpeningStock->handle();

    $this->info("{$count} opening stock ".str('entry')->plural($count).' posted.');
})->purpose('Post opening stock from the product import as OPENING stock movements');

Artisan::command('finance:backfill-journals', function (JournalBackfill $backfill) {
    $counts = $backfill->run();

    foreach ($counts as $label => $count) {
        $this->line(str_pad($label, 24).$count);
    }

    $this->info(array_sum($counts).' documents checked. Entries already posted are skipped.');
})->purpose('Post journal entries for documents created before Finance (Phase 5), oldest first');

Schedule::job(new LowStockAndExpiryAlertJob)->dailyAt('07:00');
Schedule::job(new ProcessExpiredDelegationsJob)->everyMinute();
Schedule::job(new PruneCounterEventsJob)->dailyAt('02:30');
Schedule::job(new RecalculateSalesVelocityJob)->dailyAt('02:45');
Schedule::job(new OverdueCreditReminderJob)->dailyAt('07:05');
Schedule::job(new ChequesDueReminderJob)->dailyAt('07:10');
Schedule::job(new MissingClockOutAlertJob)->dailyAt('07:15');
