<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_document_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('invoice_documents')->restrictOnDelete();
            $table->foreignId('associated_document_id')->constrained('invoice_documents')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->timestamps();

            $table->unique(['document_id', 'associated_document_id']);
            $table->index('associated_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_document_links');
    }
};
