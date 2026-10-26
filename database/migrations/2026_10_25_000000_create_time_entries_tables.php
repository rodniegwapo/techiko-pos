<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timeclock: staff clock in and out on the POS with their own PIN. One row per shift worked; an
 * open row (no clock_out_at) is someone on the clock. Every manager change to a row is kept in
 * time_entry_edits with the reason, so a timesheet can be trusted for payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->text('notes')->nullable();
            // Set when a manager typed this row in or changed it.
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['domain', 'clock_in_at']);
            $table->index(['user_id', 'clock_out_at']);
            $table->index(['location_id', 'clock_in_at']);
        });

        Schema::create('time_entry_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_edits');
        Schema::dropIfExists('time_entries');
    }
};
