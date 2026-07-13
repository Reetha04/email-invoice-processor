<?php
// database/migrations/2026_01_01_000000_create_singapore_attractions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSingaporeAttractionsTable extends Migration
{
    public function up()
    {
        Schema::create('singapore_attractions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('peak_type')->nullable(); // Peak, Off Peak, Non-Peak, Valley Season, Normal Season, Super Peak
            $table->string('ticket_type')->nullable(); // Tourist, Local, Standard, VIP
            $table->text('description')->nullable();
            $table->string('supplier')->nullable();
            $table->string('uen')->nullable();
            $table->decimal('price_adult', 10, 2)->nullable();
            $table->decimal('price_child', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('singapore_attractions');
    }
}