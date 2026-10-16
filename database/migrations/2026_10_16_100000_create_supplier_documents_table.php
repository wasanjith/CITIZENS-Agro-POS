<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The supplier's file (Purchasing → Invoices): invoices, bank deposit slips, receipts and
     * other papers, each with one or more photos / PDF pages. The files sit in the supplier's
     * folder on the local disk: supplier-invoices/{supplier-name}/{type}/...
     */
    public function up(): void
    {
        Schema::create('supplier_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->date('document_date');
            $table->string('reference', 60)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'type', 'document_date']);
        });

        Schema::create('supplier_document_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_document_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_document_files');
        Schema::dropIfExists('supplier_documents');
    }
};
