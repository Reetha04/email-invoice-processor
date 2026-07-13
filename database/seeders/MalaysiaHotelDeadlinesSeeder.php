<?php
// database/seeders/MalaysiaHotelDeadlinesSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MalaysiaHotelDeadline;

class MalaysiaHotelDeadlinesSeeder extends Seeder
{
    public function run()
    {
        $deadlines = [
            [
                'hotel_name' => 'Genting Skyworlds Hotel',
                'deadline_days' => 5,
                'deadline_description' => '5 Days Before From Check In Date'
            ],
            [
                'hotel_name' => 'First World Hotel',
                'deadline_days' => 5,
                'deadline_description' => '5 Days Before From Check In Date'
            ],
            [
                'hotel_name' => 'Resort Hotel',
                'deadline_days' => 5,
                'deadline_description' => '5 Days Before From Check In Date'
            ],
            [
                'hotel_name' => 'Crockfords Hotel',
                'deadline_days' => 5,
                'deadline_description' => '5 Days Before From Check In Date'
            ],
            [
                'hotel_name' => 'Highland Hotel',
                'deadline_days' => 5,
                'deadline_description' => '5 Days Before From Check In Date'
            ],
            [
                'hotel_name' => 'Resort World Langkawi',
                'deadline_days' => 5,
                'deadline_description' => '5 Days Before From Check In Date'
            ],
            [
                'hotel_name' => 'Bayview Hotel Langkawi',
                'deadline_days' => 5,
                'deadline_description' => '5 Days Before From Check In Date'
            ],
            // All other hotels default to D-4
        ];

        foreach ($deadlines as $deadline) {
            MalaysiaHotelDeadline::create($deadline);
        }
    }
}