<?php

namespace App\Domain\Identity\Notifications;

use App\Domain\System\Notifications\AppNotification;
use App\Models\User;

class PinLocked extends AppNotification
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly ?string $terminal,
        public readonly int $limit,
    ) {}

    public static function for(User $user, ?string $terminal): self
    {
        return new self($user->id, $user->name, $terminal, (int) config('pos.pin.max_failures_per_day', 15));
    }

    public function title(): string
    {
        return "PIN of {$this->name} locked";
    }

    public function message(): string
    {
        return "{$this->limit} wrong PINs today".($this->terminal !== null ? " (last on {$this->terminal})" : '')
            .'. The PIN works again tomorrow, or set a new one on the user page. If it was not them, someone may be guessing it.';
    }

    public function url(): string
    {
        return route('admin.users.edit', $this->userId);
    }
}
