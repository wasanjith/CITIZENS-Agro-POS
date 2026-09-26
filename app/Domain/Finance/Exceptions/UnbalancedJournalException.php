<?php

namespace App\Domain\Finance\Exceptions;

use LogicException;

/**
 * A journal entry whose debits and credits differ. Always a programming error.
 */
class UnbalancedJournalException extends LogicException
{
    public function __construct(string $description, string $debits, string $credits)
    {
        parent::__construct("Journal entry \"{$description}\" does not balance: debits {$debits}, credits {$credits}.");
    }
}
