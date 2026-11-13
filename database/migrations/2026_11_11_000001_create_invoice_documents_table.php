<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_documents', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('invoiceable');
            $table->string('type');
            $table->string('operation_key')->unique();
            $table->string('active_key')->nullable()->unique();
            $table->string('status')->index();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_set_id')->nullable();
            $table->string('reference');
            $table->char('currency', 3)->default('EUR');
            $table->bigInteger('expected_total_cents');
            $table->string('customer_vat')->nullable()->index();
            $table->string('customer_number')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_number')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('insert_sent_at')->nullable();
            $table->date('due_at')->nullable();
            $table->json('vies')->nullable();
            $table->json('payload')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_documents');
    }
};
