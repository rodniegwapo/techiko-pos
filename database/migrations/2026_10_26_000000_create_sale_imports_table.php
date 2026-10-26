<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per uploaded sales CSV (backdated sales import), plus a link from each imported sale
     * back to it. The import page's permissions are not here: on production run
     * php artisan db:seed --class=SalesImportPermissionSeeder --force
     */
    public function up(): void
    {
        Schema::create('sale_imports', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->string('file_name');
            $table->string('file_hash', 64);
            $table->unsignedInteger('rows_count');
            $table->unsignedInteger('sales_count');
            $table->json('options')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'file_hash']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('import_id')->nullable()->after('wallet_cash_movement_id')
                ->constrained('sale_imports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_id');
        });
        Schema::dropIfExists('sale_imports');
    }
};
