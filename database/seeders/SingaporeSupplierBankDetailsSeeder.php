<?php
// database/seeders/SingaporeSupplierBankDetailsSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SupplierBankDetail;

class SingaporeSupplierBankDetailsSeeder extends Seeder
{
    public function run()
    {
        $suppliers = [
            [
                'supplier_name' => 'Mega',
                'uen_number' => '200509582Z',
                'ac_name' => 'MEGA',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'A2C',
                'uen_number' => '53306566J001',
                'ac_name' => 'A2C',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Global Tix',
                'uen_number' => '201308999H',
                'ac_name' => 'Global Tix',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Cebu',
                'uen_number' => '201021297R',
                'ac_name' => 'Cebu',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Be My Guest',
                'uen_number' => '201205177M',
                'ac_name' => 'Be My Guest',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Hotel Boss',
                'uen_number' => '201021433E',
                'ac_name' => 'Hotel Boss',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'One Farrer Hotel',
                'uen_number' => '201118225E',
                'ac_name' => 'One Farrer Hotel',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Quay Hotel Little India',
                'uen_number' => '201304017H',
                'ac_name' => 'Quay Hotel Little India',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Mercure Singapore Tyrwhitt',
                'uen_number' => '199604710E',
                'ac_name' => 'Mercure Singapore Tyrwhitt',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'V Hotel Lavender',
                'uen_number' => '201021433E',
                'ac_name' => 'V Hotel Lavender',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Novotel Singapore On Kitchener',
                'uen_number' => '201910338D',
                'ac_name' => 'Novotel Singapore On Kitchener',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Ibis Singapore Novena',
                'uen_number' => '201105109C',
                'ac_name' => 'Ibis Singapore Novena',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Ibis budget Singapore selegie',
                'uen_number' => '199604710E',
                'ac_name' => 'Ibis budget Singapore selegie',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Aqueen Prestige Jalan Besar',
                'uen_number' => '200710776W',
                'ac_name' => 'Aqueen Prestige Jalan Besar',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Furama Citycentre Singapore',
                'uen_number' => '198701607D',
                'ac_name' => 'Furama Citycentre Singapore',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Orchard Hotel Singapore',
                'uen_number' => '198205246M',
                'ac_name' => 'Orchard Hotel Singapore',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Ibis Styles Singapore Albert',
                'uen_number' => '199604710E',
                'ac_name' => 'Ibis Styles Singapore Albert',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Ibis budget Singapore pearl',
                'uen_number' => '199604710E',
                'ac_name' => 'Ibis budget Singapore pearl',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
            [
                'supplier_name' => 'Ibis Budget Singapore Imperial',
                'uen_number' => '199604710E',
                'ac_name' => 'Ibis Budget Singapore Imperial',
                'account_number' => '',
                'bank' => '',
                'branch' => '',
                'swift_code' => '',
                'country_code' => 'SG'
            ],
        ];

        foreach ($suppliers as $supplier) {
            SupplierBankDetail::updateOrCreate(
                ['supplier_name' => $supplier['supplier_name']],
                $supplier
            );
        }
    }
}