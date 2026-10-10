<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
|--------------------------------------------------------------------------
| Backups (spatie/laravel-backup)
|--------------------------------------------------------------------------
|
| For now backups go to ONE place: the backup hard disk inside the shop
| server (filesystem disk "backup", folder BACKUP_PATH). The owner will add
| cloud storage later; then add its disk name to BACKUP_DISKS (see
| docs/DEPLOYMENT.md § 12) and nothing else has to change.
|
| Schedule (routes/console.php):
|   hourly 08:00–20:00  database only
|   22:00               full: database + uploaded files (attendance photos …) + .env
|   01:00               clean up old backups
|   07:20               health check (newest backup at most 1 day old)
|
*/

$disks = array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_DISKS', 'backup')))));
$maxMegabytes = (int) env('BACKUP_MAX_MEGABYTES', 50000);

return [

    'backup' => [
        'name' => env('BACKUP_NAME', 'citizens-pos'),

        'source' => [
            'files' => [
                // Only what cannot be rebuilt from git: uploaded/created files and the .env
                // (APP_KEY decrypts sessions and terminal device cookies).
                'include' => [
                    storage_path('app/private'),
                    storage_path('app/public'),
                    base_path('.env'),
                ],

                // Temporary files: report exports (kept 7 days) and import previews.
                'exclude' => [
                    storage_path('app/private/exports'),
                    storage_path('app/private/imports'),
                ],

                'follow_links' => false,
                'ignore_unreadable_directories' => true,
                'relative_path' => null,
            ],

            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',

            // "backup" = the server's backup hard disk (config/filesystems.php).
            // Later: BACKUP_DISKS=backup,cloud
            'disks' => $disks,

            // With cloud added later, a cloud outage must not stop the local backup.
            'continue_on_failure' => true,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        // Zip password (AES-256). Keep it with the owner, outside the server.
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default',

        'verify_backup' => true,
        'tries' => 2,
        'retry_delay' => 60,
    ],

    /*
     * Only problems are reported. MAIL_MAILER=log for now, so they land in
     * storage/logs; set BACKUP_NOTIFY_EMAIL and a real mailer to get e-mails.
     */
    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            'to' => env('BACKUP_NOTIFY_EMAIL', env('MAIL_FROM_ADDRESS', 'hello@example.com')),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'CITIZENS Agro POS'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],

        'webhook' => [
            'url' => '',
        ],
    ],

    'log_channel' => null,

    'monitor_backups' => [
        [
            'name' => env('BACKUP_NAME', 'citizens-pos'),
            'disks' => $disks,
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => $maxMegabytes,
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            // Every hourly database backup for 2 days …
            'keep_all_backups_for_days' => 2,
            // … then the newest of each day (the 22:00 full backup) for 14 days,
            'keep_daily_backups_for_days' => 14,
            // the newest of each week for 8 weeks,
            'keep_weekly_backups_for_weeks' => 8,
            // of each month for 12 months,
            'keep_monthly_backups_for_months' => 12,
            // and of each year for 3 years.
            'keep_yearly_backups_for_years' => 3,

            // Never fill the backup disk: delete the oldest above this size.
            'delete_oldest_backups_when_using_more_megabytes_than' => $maxMegabytes,
        ],

        'tries' => 1,
        'retry_delay' => 0,
    ],

];
