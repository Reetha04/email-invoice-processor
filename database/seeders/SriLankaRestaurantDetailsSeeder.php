<?php
// database/seeders/SriLankaRestaurantDetailsSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RestaurantDetail;

class SriLankaRestaurantDetailsSeeder extends Seeder
{
    public function run()
    {
        $restaurants = [
            ['restaurant_name' => 'Madrass Masala', 'ac_name' => 'Madras Masala Pvt Ltd', 'account_number' => '009010487069', 'bank' => 'HNB Bank'],
            ['restaurant_name' => 'Annapoorni Hotel', 'ac_name' => 'CHINMAYA MISSION OF SRILANKA', 'account_number' => '029010002526', 'bank' => 'HNB Bank'],
            ['restaurant_name' => 'Mandarin Masala', 'ac_name' => 'The Taste hut pvt ltd', 'account_number' => '039010241631', 'bank' => 'HNB Bank'],
            ['restaurant_name' => 'Owinrich Restaurent', 'ac_name' => 'AHSPremathilaka', 'account_number' => '91929532', 'bank' => 'BOC Bank'],
            ['restaurant_name' => 'Kingswood Restaurent', 'ac_name' => 'Kings Wood Tea Factory Pvt Ltd', 'account_number' => '025013392504001', 'bank' => 'Seylan Bank'],
            ['restaurant_name' => 'Ariya Restaurent', 'ac_name' => 'K P N N Lakshani', 'account_number' => '05200250037958', 'bank' => 'Peoples Bank'],
            ['restaurant_name' => 'Wave Restaurent', 'ac_name' => 'The Wave Beach Restaurant', 'account_number' => '91601093', 'bank' => 'BOC Bank'],
            ['restaurant_name' => 'Indian Summer', 'ac_name' => 'Indiaan Summer Pvt Ltd', 'account_number' => '092010091048', 'bank' => 'HNB Bank'],
            ['restaurant_name' => 'Ella Crest', 'ac_name' => 'BIVO INVESTMENTS (PVT) LTD', 'account_number' => '1520033646', 'bank' => 'Com Bank'],
            ['restaurant_name' => 'Sandy Bliss Mirissa', 'ac_name' => 'Sandy Bliss Mirissa Pvt Ltd', 'account_number' => '059010025909', 'bank' => 'HNB Bank'],
            ['restaurant_name' => 'Royal Mall Kandy', 'ac_name' => 'Royal Garden HoldingsPvt Ltd', 'account_number' => '000710014124', 'bank' => 'Sampath'],
            ['restaurant_name' => 'Serendib', 'ac_name' => 'A D P De Silva', 'account_number' => '103153716480', 'bank' => 'Sampath'],
        ];

        $countryCode = 'LK';

        foreach ($restaurants as $restaurant) {
            RestaurantDetail::updateOrCreate(
                [
                    'restaurant_name' => $restaurant['restaurant_name'],
                    'country_code' => $countryCode,
                ],
                [
                    'ac_name' => $restaurant['ac_name'],
                    'account_number' => $restaurant['account_number'],
                    'bank' => $restaurant['bank'],
                    'country_code' => $countryCode,
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('✅ Sri Lanka Restaurant Details imported successfully!');
        $this->command->info('📊 Total restaurants: ' . count($restaurants));
    }
}