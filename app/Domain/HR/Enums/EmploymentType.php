<?php

namespace App\Domain\HR\Enums;

enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Probation = 'probation';
    case Contract = 'contract';
    case Casual = 'casual';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::Probation => 'Probation',
            self::Contract => 'Contract',
            self::Casual => 'Casual',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }
}
