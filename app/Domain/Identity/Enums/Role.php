<?php

namespace App\Domain\Identity\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Manager = 'manager';
    case SalesStaff = 'sales_staff';

    public function label(): string
    {
        return config("pos.roles.{$this->value}", $this->value);
    }
}
