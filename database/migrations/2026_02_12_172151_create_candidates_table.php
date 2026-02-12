<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->nullable();
            $table->string('email')->unique(); // Unique constraint as per requirement to avoid duplicates
            $table->string('mobile')->nullable();
            $table->text('education')->nullable();
            $table->string('current_location')->nullable();
            $table->string('salary')->nullable();
            $table->string('preferred_location')->nullable();
            $table->string('resume_file_name');
            $table->string('stored_file_path');
            $table->string('email_uid')->nullable(); // To track email UIDs if needed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
