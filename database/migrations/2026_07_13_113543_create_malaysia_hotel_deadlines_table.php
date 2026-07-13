<?php
// database/migrations/2026_01_01_000005_create_malaysia_hotel_deadlines_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMalaysiaHotelDeadlinesTable extends Migration
{
    public function up()
    {
        Schema::create('malaysia_hotel_deadlines', function (Blueprint $table) {
            $table->id();
            $table->string('hotel_name');
            $table->integer('deadline_days')->default(4); // 4 = D-4, 5 = D-5, etc.
            $table->string('deadline_description')->nullable(); // "5 Days Before From Check In Date"
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('malaysia_hotel_deadlines');
    }
}