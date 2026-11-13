<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit notes are numbered under FOR UPDATE by the documents sharing a reference: without this
     * index that read would scan, and lock, every document and link.
     */
    public function up(): void
    {
        Schema::table('invoice_documents', function (Blueprint $table) {
            $table->index(['company_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'reference']);
        });
    }
};
