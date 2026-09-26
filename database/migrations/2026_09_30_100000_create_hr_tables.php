<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Working hours. working_days holds ISO weekdays (1 = Monday … 7 = Sunday).
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->json('working_days');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('code', 20)->unique();
            $table->string('full_name', 150);
            $table->string('name_si', 150)->nullable();
            $table->string('nic', 20)->nullable();
            $table->date('dob')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('designation', 80)->nullable();
            $table->date('join_date');
            $table->date('leave_date')->nullable();
            $table->string('employment_type', 20);
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_no', 40)->nullable();
            $table->string('epf_no', 30)->nullable();
            $table->boolean('is_epf_member')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // One row per employee and day. A POS sign-in creates it (clock in); ABSENT,
        // LEAVE and HOLIDAY rows have no times. Manual changes keep who and why.
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->dateTime('clock_in')->nullable();
            $table->dateTime('clock_out')->nullable();
            $table->string('source', 10);
            $table->foreignId('terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('photo_path')->nullable();
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_minutes')->default(0);
            $table->unsignedSmallInteger('ot_minutes')->default(0);
            $table->string('status', 10);
            $table->foreignId('leave_request_id')->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('edit_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index('date');
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        // days_per_year 0 = no limit (e.g. no-pay leave).
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->decimal('days_per_year', 5, 1)->default(0);
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('half_day')->default(false);
            $table->decimal('days', 5, 1);
            $table->string('reason', 255)->nullable();
            $table->string('status', 10);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'from_date']);
            $table->index('status');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreign('leave_request_id')->references('id')->on('leave_requests')->nullOnDelete();
        });

        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('type', 10);
            $table->string('calc', 15);
            $table->decimal('value', 15, 2)->default(0);
            $table->boolean('is_epf_applicable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->restrictOnDelete();
            $table->decimal('value_override', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'salary_component_id'], 'employee_component_unique');
        });

        // Cash lent to an employee, recovered from the next payslips in equal installments.
        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->unsignedSmallInteger('installments');
            $table->decimal('installment_amount', 15, 2);
            $table->decimal('recovered_amount', 15, 2)->default(0);
            $table->string('status', 12);
            $table->string('paid_from', 15);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('drawer_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique();
            $table->string('status', 12);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_epf_employee', 15, 2)->default(0);
            $table->decimal('total_epf_employer', 15, 2)->default(0);
            $table->decimal('total_etf', 15, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            // EPF and ETF sent to the government for this month.
            $table->dateTime('epf_etf_paid_at')->nullable();
            $table->foreignId('epf_etf_paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('epf_etf_reference', 100)->nullable();
            $table->timestamps();
        });

        // A snapshot of the pay calculation; names and rates are copied so later changes
        // to the employee or the settings do not change a paid payslip.
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('employee_name', 150);
            $table->string('employee_name_si', 150)->nullable();
            $table->string('designation', 80)->nullable();
            $table->string('epf_no', 30)->nullable();
            $table->boolean('is_epf_member');
            $table->decimal('basic', 15, 2);
            $table->decimal('working_days', 5, 1);
            $table->decimal('days_worked', 5, 1);
            $table->decimal('paid_leave_days', 5, 1)->default(0);
            $table->decimal('no_pay_days', 5, 1)->default(0);
            $table->decimal('no_pay_deduction', 15, 2)->default(0);
            $table->decimal('allowances', 15, 2)->default(0);
            $table->decimal('ot_hours', 7, 2)->default(0);
            $table->decimal('ot_rate', 15, 2)->default(0);
            $table->decimal('ot_amount', 15, 2)->default(0);
            $table->decimal('gross', 15, 2);
            $table->decimal('epf_base', 15, 2)->default(0);
            $table->decimal('epf_employee', 15, 2)->default(0);
            $table->decimal('epf_employer', 15, 2)->default(0);
            $table->decimal('etf', 15, 2)->default(0);
            $table->decimal('advance_deduction', 15, 2)->default(0);
            $table->decimal('other_deductions', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2);
            $table->decimal('net', 15, 2);
            // Owner's edits during review (null = calculated from attendance).
            $table->decimal('no_pay_days_override', 5, 1)->nullable();
            $table->decimal('ot_hours_override', 7, 2)->nullable();
            $table->decimal('advance_override', 15, 2)->nullable();
            $table->string('note', 255)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->string('paid_from', 15)->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('drawer_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_reference', 100)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        // manual = added by the owner during review; component = from salary components.
        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->string('component_name', 80);
            $table->string('type', 10);
            $table->decimal('amount', 15, 2);
            $table->boolean('is_epf_applicable')->default(false);
            $table->boolean('is_manual')->default(false);
        });

        // Which advance a payslip recovered and how much (undone if the run is reopened).
        Schema::create('salary_advance_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_advance_id')->constrained()->restrictOnDelete();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advance_recoveries');
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('salary_advances');
        Schema::dropIfExists('employee_salary_components');
        Schema::dropIfExists('salary_components');
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['leave_request_id']);
        });
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('shifts');
    }
};
