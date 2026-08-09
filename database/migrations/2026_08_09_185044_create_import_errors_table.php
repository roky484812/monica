<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->uuid('import_job_id');
            $table->integer('row_number');
            $table->json('row_data');
            $table->text('error_message');
            $table->timestamp('created_at');

            $table->foreign('import_job_id')->references('id')->on('import_jobs')->onDelete('cascade');

            $table->index('import_job_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('import_errors');
    }
};
