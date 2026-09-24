<?php

namespace App\Domain\Identity\Exceptions;

use DomainException;

class NonDelegablePermissionException extends DomainException
{
    /**
     * @param  list<string>  $permissions
     */
    public static function for(array $permissions): self
    {
        return new self('These permissions cannot be delegated: '.implode(', ', $permissions));
    }
}
