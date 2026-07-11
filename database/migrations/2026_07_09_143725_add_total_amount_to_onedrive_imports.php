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
    Schema::table('onedrive_imports', function (Blueprint $table) {
        $table->decimal('total_amount', 15, 2)->nullable()->after('tour_ref');
        $table->string('currency', 10)->nullable()->after('total_amount');
        $table->integer('pax_count')->nullable()->after('currency');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('onedrive_imports', function (Blueprint $table) {
            //
        });
    }
};
