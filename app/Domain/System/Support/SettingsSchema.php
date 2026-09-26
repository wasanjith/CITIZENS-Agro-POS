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
                    'invoice_sound' => ['label' => 'Play a sound on the cashier screen when a counter prints an invoice', 'type' => 'checkbox', 'rules' => ['boolean']],
                ],
            ],
            'inventory' => [
                'title' => 'Inventory',
                'description' => 'Stock rules, adjustment approval and alerts.',
                'fields' => [
                    'allow_negative_stock' => ['label' => 'Allow selling more than the stock on hand', 'type' => 'checkbox', 'rules' => ['boolean'], 'hint' => 'Leave off so stock can never go below zero.'],
                    'adjustment_approval_limit' => ['label' => 'Adjustments above this value need Super Admin approval (Rs.)', 'type' => 'number', 'rules' => ['required', 'numeric', 'min:0', 'max:100000000']],
                    'expiry_alert_days' => ['label' => 'Warn about batches expiring within (days)', 'type' => 'number', 'rules' => ['required', 'integer', 'min:1', 'max:365']],
                ],
            ],
            'customers' => [
                'title' => 'Customers & credit',
                'description' => 'Credit terms, quotations and the overdue alert.',
                'fields' => [
                    'default_credit_days' => ['label' => 'Credit days for new customers', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:365'], 'hint' => 'Days after the sale before a credit invoice is overdue. Can be changed per customer.'],
                    'quotation_valid_days' => ['label' => 'Quotations are valid for (days)', 'type' => 'number', 'rules' => ['required', 'integer', 'min:1', 'max:90']],
                    'overdue_alert' => ['label' => 'Tell the owner every morning about overdue credit', 'type' => 'checkbox', 'rules' => ['boolean']],
                ],
            ],
            'finance' => [
                'title' => 'Finance',
                'description' => 'Cheque reminders. Card and bank-transfer payments go to the bank account marked for them (Finance → Bank accounts).',
                'fields' => [
                    'cheque_alert' => ['label' => 'Tell the owner every morning about cheques to deposit and cheques falling due', 'type' => 'checkbox', 'rules' => ['boolean']],
                    'cheque_alert_days' => ['label' => 'Warn about issued cheques falling due within (days)', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:60']],
                ],
            ],
            'hr' => [
                'title' => 'HR & payroll',
                'description' => 'EPF / ETF rates, overtime and clock-in. Working hours are set per shift (HR → Shifts & holidays).',
                'fields' => [
                    'epf_employee_rate' => ['label' => 'EPF, employee share (% of EPF earnings)', 'type' => 'number', 'rules' => ['required', 'numeric', 'min:0', 'max:50']],
                    'epf_employer_rate' => ['label' => 'EPF, employer share (%)', 'type' => 'number', 'rules' => ['required', 'numeric', 'min:0', 'max:50']],
                    'etf_rate' => ['label' => 'ETF, employer (%)', 'type' => 'number', 'rules' => ['required', 'numeric', 'min:0', 'max:50']],
                    'ot_multiplier' => ['label' => 'Overtime rate (times the normal hourly rate)', 'type' => 'number', 'rules' => ['required', 'numeric', 'min:1', 'max:5']],
                    'ot_hours_divisor' => ['label' => 'Normal hourly rate = basic salary ÷', 'type' => 'number', 'rules' => ['required', 'integer', 'min:1', 'max:400'], 'hint' => '240 is usual for shop staff (Shop & Office Employees Act); 200 under the Wages Boards.'],
                    'ot_min_minutes' => ['label' => 'Overtime counts only from (minutes after closing)', 'type' => 'number', 'rules' => ['required', 'integer', 'min:0', 'max:240']],
                    'clock_in_photo' => ['label' => 'Take a webcam photo at the PIN sign-in that clocks staff in', 'type' => 'checkbox', 'rules' => ['boolean'], 'hint' => 'The browser asks once for camera access on each terminal.'],
                    'missing_clock_out_alert' => ['label' => 'Tell the owner every morning who did not clock out yesterday', 'type' => 'checkbox', 'rules' => ['boolean']],
                ],
            ],
        ];
    }
}
