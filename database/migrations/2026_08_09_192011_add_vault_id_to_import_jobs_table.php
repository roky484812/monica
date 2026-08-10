<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->uuid('vault_id')->after('user_id');
            $table->foreign('vault_id')->references('id')->on('vaults')->onDelete('cascade');
            $table->index('vault_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropForeign(['vault_id']);
            $table->dropIndex(['vault_id']);
            $table->dropColumn('vault_id');
        });
    }
};
