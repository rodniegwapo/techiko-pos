<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A POS PIN belongs to the user it was set for and has no meaning once they are gone, but its
 * foreign key held them: deleting a user who had ever been given a PIN failed outright, and the
 * PIN it left behind went on occupying those four digits for the whole organization, so nobody
 * else could be given them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_pins', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_pins', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users');
        });
    }
};
