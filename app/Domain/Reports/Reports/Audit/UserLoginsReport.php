<?php

namespace App\Domain\Reports\Reports\Audit;

use App\Domain\Reports\Report;
use App\Domain\Reports\Services\ReportLookups;
use App\Domain\Reports\Support\Column;
use App\Domain\Reports\Support\Filter;
use App\Domain\Reports\Support\ReportGroup;
use App\Domain\Reports\Support\ReportInput;
use App\Domain\Reports\Support\ReportResult;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Every sign-in (password or PIN) from the audit log.
 */
class UserLoginsReport extends Report
{
    public function __construct(private readonly ReportLookups $lookups) {}

    public function key(): string
    {
        return 'user-logins';
    }

    public function title(): string
    {
        return 'User sign-ins';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Audit;
    }

    public function description(): string
    {
        return 'Who signed in, when, where and how (password or PIN).';
    }

    public function permission(): string
    {
        return 'admin.audit.view';
    }

    public function defaultPeriod(): array
    {
        return [today()->subDays(6), today()];
    }

    public function filters(User $user): array
    {
        return [Filter::select('user', 'User', $this->lookups->users())];
    }

    public function columns(ReportInput $input): array
    {
        return [
            Column::dateTime('at', 'Time'),
            Column::text('user', 'User'),
            Column::text('method', 'Method'),
            Column::text('terminal', 'Terminal'),
        ];
    }

    public function run(ReportInput $input): ReportResult
    {
        $rows = Activity::query()
            ->where('event', 'login')
            ->where('created_at', '>=', $input->from)
            ->where('created_at', '<', $input->end())
            ->when($input->get('user'), fn ($query, $user) => $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', (int) $user))
            ->orderBy('created_at')
            ->get()
            ->map(fn (Activity $activity) => [
                'at' => $activity->created_at,
                'user' => $this->lookups->user($activity->causer_id !== null ? (int) $activity->causer_id : null),
                'method' => match ($activity->properties?->get('method')) {
                    'pin' => 'PIN',
                    'password' => 'Password',
                    default => '',
                },
                'terminal' => (string) ($activity->properties?->get('terminal') ?? ''),
            ])
            ->all();

        return new ReportResult($rows, ['Sign-ins' => number_format(count($rows))]);
    }
}
