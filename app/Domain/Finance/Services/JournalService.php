<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\SystemAccount;
use App\Domain\Finance\Exceptions\UnbalancedJournalException;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\JournalLine;
use App\Domain\Sales\Support\Money;
use App\Domain\System\Services\DocumentNumber;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only place that writes the journal.
 *
 * post() asserts Σ debit = Σ credit and writes one entry with its lines. Entries are
 * immutable: reverse() writes a mirror entry instead of changing or deleting one.
 */
class JournalService
{
    public function __construct(
        private readonly ChartOfAccounts $accounts,
        private readonly DocumentNumber $numbers,
    ) {}

    /**
     * Lines: account (system account, Account or id) with a debit or a credit. A negative
     * amount goes to the other side; zero lines are dropped. Returns null when nothing
     * is left to post.
     *
     * @param  list<array{account: SystemAccount|Account|int, debit?: BigDecimal|string|null, credit?: BigDecimal|string|null, memo?: string|null}>  $lines
     *
     * @throws UnbalancedJournalException
     */
    public function post(string $description, DateTimeInterface $date, array $lines, ?Model $source = null, ?string $event = null, ?int $userId = null, ?JournalEntry $reverses = null): ?JournalEntry
    {
        $rows = [];
        $debits = Money::zero();
        $credits = Money::zero();

        foreach ($lines as $line) {
            $amount = Money::of($line['debit'] ?? '0')->minus(Money::of($line['credit'] ?? '0'));

            if ($amount->isZero()) {
                continue;
            }

            $rows[] = [
                'account_id' => $this->accountId($line['account']),
                'debit' => (string) ($amount->isPositive() ? $amount : Money::zero()),
                'credit' => (string) ($amount->isNegative() ? $amount->negated() : Money::zero()),
                'memo' => isset($line['memo']) ? mb_substr((string) $line['memo'], 0, 255) : null,
            ];

            $amount->isPositive() ? $debits = $debits->plus($amount) : $credits = $credits->plus($amount->negated());
        }

        if ($debits->compareTo($credits) !== 0) {
            throw new UnbalancedJournalException($description, (string) $debits, (string) $credits);
        }

        if ($rows === []) {
            return null;
        }

        return DB::transaction(function () use ($description, $date, $rows, $source, $event, $userId, $reverses): JournalEntry {
            $entry = JournalEntry::create([
                'number' => $this->nextNumber(),
                'date' => $date,
                'description' => mb_substr($description, 0, 255),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'event' => $event,
                'reverses_id' => $reverses?->id,
                'created_by' => $userId ?? auth()->id(),
            ]);

            foreach ($rows as $row) {
                JournalLine::create(['journal_entry_id' => $entry->id, ...$row]);
            }

            return $entry;
        });
    }

    /**
     * Mirror entry: every debit becomes a credit and the other way round.
     */
    public function reverse(JournalEntry $entry, DateTimeInterface $date, string $description, ?int $userId = null, ?Model $source = null, ?string $event = null): JournalEntry
    {
        $lines = $entry->lines()->get()->map(fn (JournalLine $line) => [
            'account' => $line->account_id,
            'debit' => $line->credit,
            'credit' => $line->debit,
            'memo' => $line->memo,
        ])->all();

        /** @var JournalEntry */
        return $this->post($description, $date, $lines, $source ?? $entry->source()->getResults(), $event ?? 'reversal', $userId, $entry);
    }

    /**
     * The entry a document posted for an event (without its reversal), if any.
     */
    public function find(Model $source, string $event): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('event', $event)
            ->latest('id')
            ->first();
    }

    public function hasPosted(Model $source, string $event): bool
    {
        return JournalEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('event', $event)
            ->exists();
    }

    private function accountId(SystemAccount|Account|int $account): int
    {
        return match (true) {
            $account instanceof SystemAccount => $this->accounts->id($account),
            $account instanceof Account => $account->id,
            default => $account,
        };
    }

    /**
     * JE-2026-000001. The sequence is created on first use.
     */
    private function nextNumber(): string
    {
        $this->numbers->ensure('JE', 'JE-{Y}-', 6);

        return $this->numbers->next('JE');
    }
}
