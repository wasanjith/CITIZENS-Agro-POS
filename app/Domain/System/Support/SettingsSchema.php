<?php

namespace App\Domain\System\Support;

/**
 * Field definitions for the settings pages (label, input type, validation rules).
 */
class SettingsSchema
{
    /**
     * @return array<string, array{title: string, description: string, fields: array<string, array{label: string, type: string, rules: list<string>, options?: array<string, string>, hint?: string}>}>
     */
    public static function groups(): array
    {
        return [
            'shop' => [
                'title' => 'Shop profile',
                'description' => 'Printed on invoices and reports, in English and Sinhala.',
                'fields' => [
                    'name_en' => ['label' => 'Shop name (English)', 'type' => 'text', 'rules' => ['required', 'string', 'max:120']],
                    'name_si' => ['label' => 'Shop name (Sinhala)', 'type' => 'text', 'rules' => ['required', 'string', 'max:120']],
                    'address_en' => ['label' => 'Address (English)', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:255']],
                    'address_si' => ['label' => 'Address (Sinhala)', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:255']],
                    'phone' => ['label' => 'Phone', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:60']],
                ],
            ],
            'receipt' => [
                'title' => 'Invoice printing',
                'description' => 'Settings for the 80 mm thermal invoice.',
                'fields' => [
                    'language' => [
                        'label' => 'Invoice language',
                        'type' => 'select',
                        'options' => ['si' => 'Sinhala', 'en' => 'English', 'si+en' => 'Sinhala + English'],
                        'rules' => ['required', 'in:si,en,si+en'],
                    ],
                    'footer_si' => ['label' => 'Footer (Sinhala)', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:255']],
                    'footer_en' => ['label' => 'Footer (English)', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:255']],
                    'show_staff_name' => ['label' => 'Print the staff name', 'type' => 'checkbox', 'rules' => ['boolean']],
                    'show_logo' => ['label' => 'Print the logo', 'type' => 'checkbox', 'rules' => ['boolean']],
                ],
            ],
            'tax' => [
                'title' => 'Tax',
                'description' => 'Tax can also be set per product (Phase 1).',
                'fields' => [
                    'registered' => ['label' => 'VAT registered', 'type' => 'checkbox', 'rules' => ['boolean']],
                    'vat_number' => ['label' => 'VAT number', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:40']],
                    'default_rate' => ['label' => 'Default tax rate (%)', 'type' => 'number', 'rules' => ['required', 'numeric', 'min:0', 'max:100']],
                ],
            ],
            'pos' => [
                'title' => 'POS rules',
                'description' => 'Discount limits and cashier timings.',
                'fields' => [
                    'max_discount_percent_sales_staff' => ['label' => 'Max discount without approval: Sales Staff (%)', 'type' => 'number', 'rules' => ['required', 'numeric', 'min:0', 'max:100']],
                    'max_discount_percent_manager' => ['label' => 'Max discount without approval: Manager (%)', 'type' => 'number', 'rules' => ['required', 'numeric', 'min:0', 'max:100']],
                    'closing_time' => ['label' => 'Shop closing time', 'type' => 'time', 'rules' => ['required', 'date_format:H:i'], 'hint' => 'Default expiry time for a cashier handover.'],
                    'settle_warning_minutes' => ['label' => 'Warn when an invoice waits for settlement longer than (minutes)', 'type' => 'number', 'rules' => ['required', 'integer', 'min:1', 'max:120']],
                ],
            ],
        ];
    }
}
