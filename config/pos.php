<?php

/*
|--------------------------------------------------------------------------
| CITIZENS Agro POS configuration
|--------------------------------------------------------------------------
|
| The permission catalogue below is the single source of truth for roles
| and permissions. PermissionSeeder reads it and the permission-matrix test
| verifies it (docs/IMPLEMENTATION_PLAN.md section 4).
|
| Each permission maps to:
|   roles     – roles that hold it permanently
|   delegable – true:  included in the default Cashier Handover scope
|               false: may never be delegated (CreateDelegationAction rejects it)
|               null:  not part of a handover (normal role permission)
|
*/

return [

    'roles' => [
        'super_admin' => 'Super Admin',
        'manager' => 'Manager',
        'sales_staff' => 'Sales Staff',
    ],

    'permissions' => [
        // POS
        'pos.sell' => ['roles' => ['super_admin', 'manager', 'sales_staff'], 'delegable' => null],
        'pos.reprint' => ['roles' => ['super_admin', 'manager', 'sales_staff'], 'delegable' => null],
        'pos.discount.basic' => ['roles' => ['super_admin', 'manager', 'sales_staff'], 'delegable' => null],
        'pos.settle' => ['roles' => ['super_admin'], 'delegable' => true],
        'pos.live_view' => ['roles' => ['super_admin'], 'delegable' => true],
        'pos.void' => ['roles' => ['super_admin'], 'delegable' => true],
        'pos.refund' => ['roles' => ['super_admin'], 'delegable' => true],
        'pos.discount.override' => ['roles' => ['super_admin'], 'delegable' => true],
        'pos.approve_requests' => ['roles' => ['super_admin'], 'delegable' => true],
        'drawer.manage' => ['roles' => ['super_admin'], 'delegable' => true],
        'drawer.handover' => ['roles' => ['super_admin'], 'delegable' => null],

        // Catalog
        'catalog.view' => ['roles' => ['super_admin', 'manager', 'sales_staff'], 'delegable' => null],
        'catalog.manage' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'catalog.prices.manage' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'catalog.cost.view' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'catalog.synonyms.manage' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],

        // Inventory
        'inventory.view' => ['roles' => ['super_admin', 'manager', 'sales_staff'], 'delegable' => null],
        'inventory.adjust' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'inventory.adjust.approve' => ['roles' => ['super_admin'], 'delegable' => null],
        'inventory.stocktake' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],

        // Purchasing
        'purchasing.po.create' => ['roles' => ['super_admin', 'manager', 'sales_staff'], 'delegable' => null],
        'purchasing.po.approve' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'purchasing.grn.create' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'purchasing.suppliers.manage' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'purchasing.suppliers.pay' => ['roles' => ['super_admin'], 'delegable' => null],

        // Customers
        'customers.view' => ['roles' => ['super_admin', 'manager', 'sales_staff'], 'delegable' => null],
        'customers.manage' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'customers.credit.manage' => ['roles' => ['super_admin', 'manager'], 'delegable' => true],

        // HR
        'hr.attendance.self' => ['roles' => ['super_admin', 'manager', 'sales_staff'], 'delegable' => null],
        'hr.attendance.view' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'hr.attendance.edit' => ['roles' => ['super_admin'], 'delegable' => false],
        'hr.employees.manage' => ['roles' => ['super_admin'], 'delegable' => false],
        'hr.payroll.manage' => ['roles' => ['super_admin'], 'delegable' => false],

        // Finance
        'finance.banks.manage' => ['roles' => ['super_admin'], 'delegable' => false],
        'finance.cheques.manage' => ['roles' => ['super_admin'], 'delegable' => false],
        'finance.expenses.manage' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'finance.journal.view' => ['roles' => ['super_admin'], 'delegable' => false],

        // Reports
        'reports.sales' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'reports.inventory' => ['roles' => ['super_admin', 'manager'], 'delegable' => null],
        'reports.profit' => ['roles' => ['super_admin'], 'delegable' => false],
        'reports.finance' => ['roles' => ['super_admin'], 'delegable' => false],
        'reports.hr' => ['roles' => ['super_admin'], 'delegable' => false],

        // Administration
        'admin.users.manage' => ['roles' => ['super_admin'], 'delegable' => false],
        'admin.terminals.manage' => ['roles' => ['super_admin'], 'delegable' => false],
        'admin.settings.manage' => ['roles' => ['super_admin'], 'delegable' => false],
        'admin.audit.view' => ['roles' => ['super_admin'], 'delegable' => false],
    ],

    /*
    | Name of the long-lived cookie that binds a browser to a registered terminal.
    */
    'device_cookie' => env('POS_DEVICE_COOKIE', 'citizens_terminal'),

    /*
    | Device cookie lifetime in minutes (5 years).
    */
    'device_cookie_minutes' => 60 * 24 * 365 * 5,

    'pin' => [
        'min_length' => 4,
        'max_length' => 6,
        'max_attempts_per_minute' => 5,
    ],

    /*
    | Default values for the settings table, grouped as they appear on the
    | settings pages. Keys are stored as "group.key".
    */
    'settings_defaults' => [
        'shop' => [
            'name_en' => 'CITIZENS Agro',
            'name_si' => 'සිටිසන්ස් ඇග්‍රෝ',
            'address_en' => '',
            'address_si' => '',
            'phone' => '',
        ],
        'receipt' => [
            'language' => 'si',
            'footer_en' => 'Thank you! Goods once sold are not returnable without the invoice.',
            'footer_si' => 'ස්තූතියි! ඉන්වොයිසිය නොමැතිව විකුණූ භාණ්ඩ ආපසු භාර ගනු නොලැබේ.',
            'show_staff_name' => true,
            'show_logo' => false,
        ],
        'tax' => [
            'registered' => false,
            'vat_number' => '',
            'default_rate' => 0,
        ],
        'pos' => [
            'max_discount_percent_sales_staff' => 5,
            'max_discount_percent_manager' => 10,
            'closing_time' => '18:00',
            'settle_warning_minutes' => 5,
            'invoice_sound' => false,
        ],
        'inventory' => [
            'allow_negative_stock' => false,
            'adjustment_approval_limit' => 10000,
            'expiry_alert_days' => 30,
        ],
        'customers' => [
            'default_credit_days' => 30,
            'quotation_valid_days' => 7,
            'overdue_alert' => true,
        ],
        'finance' => [
            'cheque_alert' => true,
            'cheque_alert_days' => 3,
        ],
        'hr' => [
            'epf_employee_rate' => 8,
            'epf_employer_rate' => 12,
            'etf_rate' => 3,
            'ot_multiplier' => 1.5,
            'ot_hours_divisor' => 240,
            'ot_min_minutes' => 30,
            'clock_in_photo' => false,
            'missing_clock_out_alert' => true,
        ],
    ],

];
