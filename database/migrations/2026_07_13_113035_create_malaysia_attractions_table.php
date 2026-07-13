<?php
// database/migrations/2026_01_01_000003_create_malaysia_attractions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMalaysiaAttractionsTable extends Migration
{
    public function up()
    {
        Schema::create('malaysia_attractions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('portal')->nullable();
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
        Schema::dropIfExists('malaysia_attractions');
    }
}