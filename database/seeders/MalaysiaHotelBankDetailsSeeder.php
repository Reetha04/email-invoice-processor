<?php
// database/seeders/MalaysiaHotelBankDetailsSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MalaysiaHotelBankDetailsSeeder extends Seeder
{
    public function run()
    {
        $hotels = [
            [
                'hotel_name' => 'Furama Bukit Bintang',
                'ac_name' => 'Grace Hub Sdn Bh',
                'account_number' => '8001 5647 67',
                'bank' => 'Cimb Bank Bhd',
                'branch' => 'Jalan P Ramlee',
                'swift_code' => 'CIBBMYKL',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'First World Hotel',
                'ac_name' => 'H.I.S. Travel (Malaysia) Sdn Bh',
                'account_number' => '514084254829',
                'bank' => 'Maybank Berhad',
                'branch' => '',
                'swift_code' => 'MBBEMYKL',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Ramada Encore',
                'ac_name' => 'Mg Captial Sdn Bhd',
                'account_number' => '8008493273',
                'bank' => 'Cimb Bank Bhd',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Dayang Bay Resort Langkawi',
                'ac_name' => 'Layar Baiduri Sdn Bhd',
                'account_number' => '8004076111',
                'bank' => 'Cimb Bank Bhd',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Awana Hotel',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Cosmo Hotel',
                'ac_name' => 'Superb Upline Sdn Bhd',
                'account_number' => '140820011068933',
                'bank' => 'Alliance Berhad',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Bella Vista Water Front',
                'ac_name' => 'Langkawi Permai Sdn Bhd',
                'account_number' => '514486301381',
                'bank' => 'Malayan Banking Berhad',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Grand Continental Hotel',
                'ac_name' => 'Hotel Grand Continental',
                'account_number' => '014020310304',
                'bank' => 'Maybank Berhad',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Bay View',
                'ac_name' => 'Bayview Hotel Sendirian',
                'account_number' => '8010914625',
                'bank' => 'Cimb Bank Bhd',
                'branch' => 'Berhad',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Royal Chulan',
                'ac_name' => 'Boustead Hotels & Resorts Sdn Bhd',
                'account_number' => '10026016603-7',
                'bank' => 'Affin Berhad',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Metro Hotel Bukit Bintang',
                'ac_name' => 'Nu Travel & Tour',
                'account_number' => '8010987508',
                'bank' => 'Cimb Bank Bhd',
                'branch' => 'Berhad',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'The Pearl Hotel',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Ramada Suites Kuala Lumpur City Centre',
                'ac_name' => 'Trinidad Signature Suites Sdn Bhd',
                'account_number' => '21423100042595',
                'bank' => 'Rhb Bank Berhad',
                'branch' => 'Jalan',
                'swift_code' => 'RHBBMYKL',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Berjaya Time Square',
                'ac_name' => 'Resort World Tours Sdn Bhd',
                'account_number' => '514356805704',
                'bank' => 'Maybank Berhad',
                'branch' => '',
                'swift_code' => 'MBBEMYKL',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Arena Star Hotel',
                'ac_name' => 'Arenaa Star Hotel Sdn. Bhd',
                'account_number' => '3186028633',
                'bank' => 'Public Bank',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Resort World Langkawi',
                'ac_name' => 'Resort World Tours Sdn Bhd',
                'account_number' => '514356805704',
                'bank' => 'Maybank Berhad',
                'branch' => '',
                'swift_code' => 'MBBEMYKL',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Langkawi Sea View',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Ibis Styles Kuala Lumpur Fraser Business Park',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Swiss Garden',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'De Greenish Village Langkawi',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Adya Hotel Langkawi',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Berjaya Langkawi Resort',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Verdant Hill Kuala Lumpur',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Grand Ion Delemon',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Hotel Neo Penang',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Metrostar Hotel Kuala Lumpur',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Berjaya Penang Hotel',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Rebak Island Resort & Marina, Langkawi',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Ibis Kuala Lumpur City Centre',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Ancasa Hotel KL',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
            [
                'hotel_name' => 'Pelangi Beach Resort & Spa',
                'ac_name' => '',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'MY'
            ],
        ];

        foreach ($hotels as $hotel) {
            DB::table('hotel_bank_details')->updateOrInsert(
                ['hotel_name' => $hotel['hotel_name'], 'country_code' => 'MY'],
                $hotel
            );
        }
    }
}