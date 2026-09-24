<?php

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\Terminal;

/**
 * Holds the terminal (device) the current request comes from, if the browser is registered.
 *
 * Registered as a scoped singleton and filled by the ResolveTerminal middleware.
 */
class CurrentTerminal
{
    private ?Terminal $terminal = null;

    public function set(?Terminal $terminal): void
    {
        $this->terminal = $terminal;
    }

    public function get(): ?Terminal
    {
        return $this->terminal;
    }

    public function exists(): bool
    {
        return $this->terminal !== null;
    }
}
