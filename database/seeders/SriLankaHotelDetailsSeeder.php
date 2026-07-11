<?php
// database/seeders/SriLankaHotelDetailsSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\HotelDetail;
use Illuminate\Support\Facades\Log;

class SriLankaHotelDetailsSeeder extends Seeder
{
    public function run()
    {
        $hotels = [
            ['hotel_name' => 'Wisdom Boutique Resort', 'ac_name' => 'WISDOM BOUTIQUE RESORT', 'bank' => 'Com Bank', 'account_number' => '1000695555', 'branch' => 'Kandy', 'bank_and_branch' => 'Com Bank Kandy', 'swift' => null],
            ['hotel_name' => 'Weligama Bay Marriott', 'ac_name' => 'WELIGAMA HOTEL PROPERTIES LTD', 'bank' => 'Hatton National Bank', 'account_number' => '213020045887', 'branch' => 'Weligama', 'bank_and_branch' => 'Hatton National Bank Weligama', 'swift' => 'HBLILKLX213'],
            ['hotel_name' => 'Victoria golf and country resort', 'ac_name' => 'Rajawella Holdings Limited', 'bank' => 'Nations Trust Bank', 'account_number' => '4100015869', 'branch' => 'Kandy', 'bank_and_branch' => 'Nations Trust Bank Kandy', 'swift' => 'NTBCLKLX004'],
            ['hotel_name' => 'Victoria Court Suites Hotel', 'ac_name' => 'Victoria Court Suites Pvt Ltd', 'bank' => 'Sampath Bank', 'account_number' => '22410003132', 'branch' => 'Kesbewa', 'bank_and_branch' => 'Sampath Bank Kesbewa', 'swift' => 'BSAMLKLX224'],
            ['hotel_name' => 'Uppuweli Beach by DSK', 'ac_name' => 'Dsk Beach Resort (Pvt) Ltd', 'bank' => 'Bank of Ceylon', 'account_number' => '92725096', 'branch' => 'Uppuweli', 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'Unique Cottages', 'ac_name' => 'ICON HOTELS PVT LTD', 'bank' => 'Com Bank', 'account_number' => '1901008534', 'branch' => 'City Branch', 'bank_and_branch' => 'Com Bank City Branch', 'swift' => null],
            ['hotel_name' => 'Turya Kaluthara', 'ac_name' => 'TURYAA PVT LTD', 'bank' => 'Hatton National Bank', 'account_number' => '34010004937', 'branch' => 'Kalutara', 'bank_and_branch' => 'Hatton National Bank Kalutara', 'swift' => 'HBLILKLX034'],
            ['hotel_name' => 'Tropical Life Resort & Spa Dambulla', 'ac_name' => 'Tropical Village', 'bank' => 'Seylan Bank', 'account_number' => '106013815311002', 'branch' => 'Narammala', 'bank_and_branch' => 'Seylan Bank Narammala', 'swift' => 'SEYBLKLX106'],
            ['hotel_name' => 'Tropical Life Resort', 'ac_name' => 'Tropical Village Resort Pvt Ltd', 'bank' => 'Com Bank', 'account_number' => '1540027775', 'branch' => 'Dambulla', 'bank_and_branch' => 'Com Bank Dambulla', 'swift' => null],
            ['hotel_name' => 'Triple O Six', 'ac_name' => 'MANAMPERI HOTELS AND RESORTS PVT LTD', 'bank' => 'Hatton National Bank', 'account_number' => '42010015919', 'branch' => 'Matara', 'bank_and_branch' => 'Hatton National Bank Matara', 'swift' => 'HBLILKLX042'],
            ['hotel_name' => 'Trincomalee Beach Resort & Spa', 'ac_name' => 'Trincomalee Beach Resort & Spa (PVT) Ltd', 'bank' => 'Hatton National Bank', 'account_number' => '96010212535', 'branch' => null, 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'Trinco Blu by Cinnamon', 'ac_name' => 'Trinco Holiday Resorts Pvt Ltd', 'bank' => 'NTB', 'account_number' => '6212096337', 'branch' => null, 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'Tribe Yala', 'ac_name' => 'TRIBE YALA (PVT) LTD', 'bank' => 'Nations Trust Bank', 'account_number' => '100060019735', 'branch' => 'Corporate', 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'W15 Lake Gregory', 'ac_name' => 'W 15 LAKESIDE (PRIVATE) LIMITED', 'bank' => 'Sampath Bank PLC', 'account_number' => '0001 1009 6763', 'branch' => 'City Branch', 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'Topaz Hotel', 'ac_name' => 'TOPAZ HOTELS LIMITED', 'bank' => 'Hatton National Bank', 'account_number' => '18010654955', 'branch' => 'Kandy', 'bank_and_branch' => 'Hatton National Bank Kandy', 'swift' => 'HBLILKLX018'],
            ['hotel_name' => 'Tip Top Boutique Hotel', 'ac_name' => 'Tip Top Boutique Hotel', 'bank' => 'Bank of Ceylon', 'account_number' => '87194248', 'branch' => 'Ella Branch', 'bank_and_branch' => 'Bank of Ceylon Ella Branch', 'swift' => null],
            ['hotel_name' => 'Tilko Jaffna City Hotel', 'ac_name' => 'Tilko Jaffna City Hotels(Pvt )Ltd', 'bank' => 'DFCC Bank', 'account_number' => '101090973844', 'branch' => null, 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'The Villas Wadduwa (Amaya)', 'ac_name' => 'Sunset Beach Resort & Spa ( PVT) Ltd', 'bank' => 'Sampath Bank', 'account_number' => '2930034846', 'branch' => null, 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'The Valampuri', 'ac_name' => 'The Valampuri', 'bank' => 'HNB', 'account_number' => '1500 1000 3255', 'branch' => null, 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'The Surf Ekho Surf', 'ac_name' => 'United Hotels Company Ltd', 'bank' => 'Hatton National Bank', 'account_number' => '109010013939', 'branch' => 'Aluthgama', 'bank_and_branch' => 'Hatton National Bank Aluthgama', 'swift' => 'HBLILKLX109'],
            ['hotel_name' => 'The Safari Ekho Safari', 'ac_name' => 'TISSA RESORT PVT LTD', 'bank' => 'Hatton National Bank', 'account_number' => '90010009799', 'branch' => 'Tissamarama', 'bank_and_branch' => 'Hatton National Bank Tissamarama', 'swift' => 'HBLILKLX090'],
            ['hotel_name' => 'The Rain Tree', 'ac_name' => 'Oak Ray Rain Tree Hotel (Pvt) Ltd.', 'bank' => 'Hatton National Bank', 'account_number' => '18010670467', 'branch' => 'Kandy', 'bank_and_branch' => 'Hatton National Bank Kandy', 'swift' => 'HBLILKLX018'],
            ['hotel_name' => 'The Queensburry', 'ac_name' => 'The Queensbury City Hotel Pvt Ltd', 'bank' => 'Hatton National Bank', 'account_number' => '33010011278', 'branch' => 'Welimada', 'bank_and_branch' => 'Hatton National Bank Welimada', 'swift' => 'HBLILKLX033'],
            ['hotel_name' => 'The Ocean Colombo', 'ac_name' => 'THE OCEAN COLOMBO PVT LTD', 'bank' => 'Com Bank', 'account_number' => '1114031000', 'branch' => 'Katubedda', 'bank_and_branch' => 'Com Bank Katubedda', 'swift' => null],
            ['hotel_name' => 'The Long Beach Resort', 'ac_name' => 'THE LONG BEACH RESORT PVT LTD', 'bank' => 'Peoples Bank', 'account_number' => '329100170030061', 'branch' => 'Koggala', 'bank_and_branch' => 'Peoples Bank Koggala', 'swift' => 'PSBKLKLX329'],
            ['hotel_name' => 'The Grand Turf', 'ac_name' => 'La Grand Hotel and Resort pvt Ltd', 'bank' => 'Hatton National Bank', 'account_number' => '33010016167', 'branch' => 'Nuwara Eliya', 'bank_and_branch' => 'Hatton National Bank Nuwara Eliya', 'swift' => 'HBLILKLX028'],
            ['hotel_name' => 'The Golden Ridge', 'ac_name' => 'The Golden Ridge Hotel Pvt Ltd', 'bank' => 'Bank of Ceylon', 'account_number' => '84002259', 'branch' => 'Peradeniya', 'bank_and_branch' => 'Bank of Ceylon Peradeniya', 'swift' => 'BCEYLKLX588'],
            ['hotel_name' => 'The Golden Crown', 'ac_name' => 'THE GOLDEN CROWN HOTEL (PVT) LTD', 'bank' => 'Commercial', 'account_number' => '8027077784', 'branch' => 'Kandy City Centre', 'bank_and_branch' => 'Commercial Kandy City Centre', 'swift' => null],
            ['hotel_name' => 'Thaala Bentota', 'ac_name' => 'Serandib Hotels PLC', 'bank' => 'Com Bank', 'account_number' => '1400847201', 'branch' => 'Foreign Branch', 'bank_and_branch' => 'Com Bank Foreign Branch', 'swift' => null],
            ['hotel_name' => 'Temple Tree Resort & Spa', 'ac_name' => 'TEMPLE TREE INDURUWA PRIVATE LIMITED', 'bank' => 'Com Bank', 'account_number' => '1400027950', 'branch' => 'Aluthgama', 'bank_and_branch' => 'Com Bank Aluthgama', 'swift' => null],
            ['hotel_name' => 'Taj Samudra', 'ac_name' => 'TAL LANKA HOTELS PLC', 'bank' => 'Standard Chartered Bank', 'account_number' => '1500731601', 'branch' => 'Head Office', 'bank_and_branch' => 'Standard Chartered Bank - Head Office', 'swift' => 'SCBLLKLX999'],
            ['hotel_name' => 'Taj Bentota Resort and Spa', 'ac_name' => 'LANKA ISLAND RESORTS LTD', 'bank' => 'Bank of Ceylon', 'account_number' => '000-2759003', 'branch' => 'Bentota', 'bank_and_branch' => 'Bank of Ceylon Bentota', 'swift' => 'BCEYLKLX102'],
            ['hotel_name' => 'Taj Bentota Resort', 'ac_name' => 'LANKA ISLAND RESORTS LTD', 'bank' => 'Bank of Ceylon', 'account_number' => '2759003', 'branch' => 'Bentota', 'bank_and_branch' => 'Bank of Ceylon Bentota', 'swift' => 'BCEYLKLX102'],
            ['hotel_name' => 'Sigiriya Kingdom Resort', 'ac_name' => 'SIGIRIYA KINGDOM RESORT & SPA PVT LTD', 'bank' => 'PAN ASIA BANK', 'account_number' => '104911100072', 'branch' => null, 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'Shangri-La Colombo', 'ac_name' => 'Shangri La Hotels Lanka Private Limited', 'bank' => 'Standard Chartered Bank', 'account_number' => '1500732601', 'branch' => 'Head Office', 'bank_and_branch' => 'Standard Chartered Bank - Head Office', 'swift' => 'SCBLLKLX999'],
            ['hotel_name' => 'Royal Classic Resort', 'ac_name' => 'ROYAL CLASSIC RESORT PVT LTD', 'bank' => 'Com Bank', 'account_number' => '1000250501', 'branch' => 'Kandy', 'bank_and_branch' => 'Com Bank Kandy', 'swift' => null],
            ['hotel_name' => 'Royal Classic', 'ac_name' => 'ROYAL CLASSIC RESORT PVT LTD', 'bank' => 'Com Bank', 'account_number' => '1000250501', 'branch' => 'Kandy', 'bank_and_branch' => 'Com Bank Kandy', 'swift' => null],
            ['hotel_name' => 'Ramada colombo', 'ac_name' => 'Alhambra Hotels Ltd', 'bank' => 'Bank of Ceylon', 'account_number' => '240', 'branch' => 'Corporate Barnch', 'bank_and_branch' => 'Bank of Ceylon Corporate Barnch', 'swift' => 'BCEYLKLX660'],
            ['hotel_name' => 'Ramada by Wyndham Katunayake', 'ac_name' => 'SIRIMEDURA HOSPITALITIES PVT LTD', 'bank' => 'Bank of Ceylon', 'account_number' => '88594300', 'branch' => 'Corporate Barnch', 'bank_and_branch' => 'Bank of Ceylon Corporate Barnch', 'swift' => 'BCEYLKLX660'],
            ['hotel_name' => 'Radisson kandy', 'ac_name' => 'SUISSE HOTEL KANDY PVT LTD', 'bank' => 'Bank of Ceylon', 'account_number' => '77963611', 'branch' => 'KANDY', 'bank_and_branch' => 'Bank of Ceylon KANDY', 'swift' => 'BCEYLKLX002'],
            ['hotel_name' => 'Radisson Hotel Colombo', 'ac_name' => 'SINO LANKA HOTELS COLOMBO PVT LTD', 'bank' => 'Sampath Bank', 'account_number' => '2930025448', 'branch' => 'Headquarters Branch', 'bank_and_branch' => 'Sampath Bank Headquarters Branch', 'swift' => 'BSAMLKLX029'],
            ['hotel_name' => 'Radisson Collection Resort, Galle', 'ac_name' => 'Serenia Limited', 'bank' => 'Nations Trust Bank PLC', 'account_number' => '1000 6001 5676', 'branch' => 'Union Place', 'bank_and_branch' => null, 'swift' => 'NTBCLKLX'],
            ['hotel_name' => 'Pelwehera Village Resort', 'ac_name' => 'PELWEHERA VILLAGE RESORT PVT LTD', 'bank' => 'Hatton National Bank', 'account_number' => '18010430892', 'branch' => 'Kandy', 'bank_and_branch' => 'Hatton National Bank Kandy', 'swift' => 'HBLILKLX018'],
            ['hotel_name' => 'Pegasus Reef Hotel', 'ac_name' => 'PEGASUS HOTELS OF CEYLON PLC', 'bank' => 'Hatton National Bank', 'account_number' => '131010007816', 'branch' => 'Hendala', 'bank_and_branch' => 'Hatton National Bank Hendala', 'swift' => 'HBLILKLX131'],
            ['hotel_name' => 'Pearl Grand', 'ac_name' => 'RATHNA PEARL GRAND CITY HOTEL PVT LTD', 'bank' => 'Sampath', 'account_number' => '3810003803', 'branch' => 'Kaduruwela', 'bank_and_branch' => 'Sampath Kaduruwela', 'swift' => null],
            ['hotel_name' => 'Oakray Wild Yala Wild Tissa', 'ac_name' => 'Oak Ray Wild Yala Restaurant (Pvt) Ltd', 'bank' => 'Hatton National Bank', 'account_number' => '18010675338', 'branch' => 'Kandy', 'bank_and_branch' => 'Hatton National Bank Kandy', 'swift' => 'HBLILKLX018'],
            ['hotel_name' => 'NH collection Colombo', 'ac_name' => 'Softlogic City Hotels (PVT) Ltd', 'bank' => 'BANK OF CEYLON', 'account_number' => '72568460', 'branch' => null, 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'NH Bentota Ceysands Resort', 'ac_name' => 'Ceysand Resorts Limited', 'bank' => 'Nations Trust Bank Plc.', 'account_number' => '1100056990', 'branch' => null, 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'Mandara Resort', 'ac_name' => 'MANDARA RESORTS PVT LTD', 'bank' => 'Com Bank', 'account_number' => '1112013535', 'branch' => 'Weligama', 'bank_and_branch' => 'Com Bank Weligama', 'swift' => null],
            ['hotel_name' => 'Radisson Hotel Kandy (OZO Kandy)', 'ac_name' => 'SUISSE HOTEL KANDY (PVT) LTD', 'bank' => 'BOC', 'account_number' => '77963611', 'branch' => 'BOC', 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'Joes Unawatuna', 'ac_name' => 'Jolanka Resorts Pvt Ltd', 'bank' => 'Hatton National Bank', 'account_number' => '43010151502', 'branch' => 'Kirulapone', 'bank_and_branch' => 'Hatton National Bank Kirulapone', 'swift' => 'HBLILKLX043'],
            ['hotel_name' => 'Joes Resort Bentota', 'ac_name' => 'JOES RESORT BENTOTA PVT LTD', 'bank' => 'Hatton National Bank', 'account_number' => '43010156491', 'branch' => 'Kirulapone', 'bank_and_branch' => 'Hatton National Bank Kirulapone', 'swift' => 'HBLILKLX043'],
            ['hotel_name' => 'Jie Jie Beach by Jetwing', 'ac_name' => 'Jie Zhong Jie Lanka Developing Construction and Engineering (Pvt) Lt', 'bank' => 'Sampath Bank', 'account_number' => '2610009274', 'branch' => 'Panadura', 'bank_and_branch' => null, 'swift' => null],
            ['hotel_name' => 'Jetwing blue', 'ac_name' => 'BLUE OCEANIC BEACH HOTEL PVT LTD', 'bank' => 'Sampath Bank', 'account_number' => '2410002275', 'branch' => 'Negombo', 'bank_and_branch' => 'Sampath Bank Negombo', 'swift' => 'BSAMLKLX024'],
            ['hotel_name' => 'Habarana Village By Cinnamon', 'ac_name' => 'Habarana Walk Inn Ltd', 'bank' => 'Nations Trust Bank', 'account_number' => '1100056849', 'branch' => 'Corporate Bran', 'bank_and_branch' => 'Nations Trust Bank Corporate Bran', 'swift' => 'NTBCLKLX006'],
            ['hotel_name' => 'Grand Bell', 'ac_name' => 'Marine Drive Hotels Pvt Ltd', 'bank' => 'Hatton National Bank', 'account_number' => '115010154576', 'branch' => 'Kollupitiya', 'bank_and_branch' => 'Hatton National Bank Kollupitiya', 'swift' => 'HBLILKLX115'],
            ['hotel_name' => 'Cinnamon Wild Yala', 'ac_name' => 'YALA VILLAGE PRIVATE LTD', 'bank' => 'Nations Trust Bank', 'account_number' => '6212096349', 'branch' => 'Corporate Bran', 'bank_and_branch' => 'Nations Trust Bank Corporate Bran', 'swift' => 'NTBCLKLX006'],
            ['hotel_name' => 'Cinnamon Lodge', 'ac_name' => 'Habarana Lodge Ltd', 'bank' => 'Nations Trust Bank', 'account_number' => '1100056837', 'branch' => 'Corporate Bran', 'bank_and_branch' => 'Nations Trust Bank Corporate Bran', 'swift' => 'NTBCLKLX006'],
            ['hotel_name' => 'Cinnamon Lakeside', 'ac_name' => 'TRANS ASIA HOTELS PLC', 'bank' => 'Citi Bank N.A.', 'account_number' => '5200911018', 'branch' => 'Head office(Br.001)', 'bank_and_branch' => 'Citi Bank N.A. Head office(Br.001)', 'swift' => 'CITILKLX001'],
            ['hotel_name' => 'Cinnamon Bey', 'ac_name' => 'BERUWALA HOLIDAY RESORTS PVT LTD', 'bank' => 'Nations Trust Bank', 'account_number' => '6212096350', 'branch' => 'Corporate Bran', 'bank_and_branch' => 'Nations Trust Bank Corporate Bran', 'swift' => 'NTBCLKLX006'],
            ['hotel_name' => 'Cinnamon Bentota Beach', 'ac_name' => 'Ceylon Holiday Resorts Ltd', 'bank' => 'Nations Trust Bank', 'account_number' => '1100055341', 'branch' => 'Corporate Bran', 'bank_and_branch' => 'Nations Trust Bank Corporate Bran', 'swift' => 'NTBCLKLX006'],
            ['hotel_name' => 'Blue Beach Wadduwa', 'ac_name' => 'THE BLUE BEACH EXOTICA PVT LTD', 'bank' => 'Sampath Bank', 'account_number' => '110087519', 'branch' => 'City Office', 'bank_and_branch' => 'Sampath Bank City Office', 'swift' => 'BSAMLKLX001'],
            ['hotel_name' => 'Blue Beach Galle', 'ac_name' => 'MANAGEMENT AND GENERAL INVESTMENT COMBINE PVT LTD', 'bank' => 'Hatton National Bank', 'account_number' => '13010032909', 'branch' => 'Galle', 'bank_and_branch' => 'Hatton National Bank Galle', 'swift' => 'HBLILKLX013'],
            ['hotel_name' => 'Blue Beach', 'ac_name' => 'THE BLUE BEACH EXOTICA PVT LTD', 'bank' => 'Sampath Bank', 'account_number' => '110087519', 'branch' => 'City Office', 'bank_and_branch' => 'Sampath Bank City Office', 'swift' => 'BSAMLKLX001'],
            ['hotel_name' => 'Black pool Nuwara Eliya', 'ac_name' => 'BLACKPOOL HOLDINGS PVT LIMITED', 'bank' => 'Com Bank', 'account_number' => '1270062700', 'branch' => 'BORELLA', 'bank_and_branch' => 'Com Bank BORELLA', 'swift' => null],
            ['hotel_name' => 'Best Western', 'ac_name' => 'ELYON COLOMBO PVT LTD', 'bank' => 'Nations Trust Bank', 'account_number' => '100810007391', 'branch' => 'Narahenpita', 'bank_and_branch' => 'Nations Trust Bank Narahenpita', 'swift' => 'NTBCLKLX081'],
            ['hotel_name' => 'Berjaya Hotel', 'ac_name' => 'BERJAYA MOUNT ROYAL BEACH HOTEL', 'bank' => 'Seylan Bank', 'account_number' => '3000171218001', 'branch' => 'Mount Lavinia', 'bank_and_branch' => 'Seylan Bank Mount Lavinia', 'swift' => 'SEYBLKLX003'],
            ['hotel_name' => 'Ashford', 'ac_name' => 'N.R.RUNAGE', 'bank' => 'Sampath Bank', 'account_number' => '1050061600', 'branch' => 'Matara', 'bank_and_branch' => 'Sampath Bank Matara', 'swift' => 'BSAMLKLX010'],
            ['hotel_name' => 'Araliya Red', 'ac_name' => 'P A PROPERTIES PVT LTD', 'bank' => 'Seylan Bank', 'account_number' => '19013002176004', 'branch' => 'Nuwara Eliya', 'bank_and_branch' => 'Seylan Bank Nuwara Eliya', 'swift' => 'SEYBLKLX019'],
            ['hotel_name' => 'Araliya Green Hills', 'ac_name' => 'ARALIYA GREEN HILLS HOTEL PVT LTD', 'bank' => 'Sampath Bank', 'account_number' => '3810001517', 'branch' => 'Kaduruwela', 'bank_and_branch' => 'Sampath Bank Kaduruwela', 'swift' => 'BSAMLKLX038'],
            ['hotel_name' => 'Araliya Green City', 'ac_name' => 'Araliya Green City (Pvt) Ltd', 'bank' => 'Com Bank', 'account_number' => '1880035200', 'branch' => 'Kaduruwela', 'bank_and_branch' => 'Com Bank Kaduruwela', 'swift' => null],
        ];

        $countryCode = 'LK'; // Sri Lanka

        foreach ($hotels as $hotel) {
            HotelDetail::updateOrCreate(
                [
                    'hotel_name' => $hotel['hotel_name'],
                    'country_code' => $countryCode,
                ],
                [
                    'ac_name' => $hotel['ac_name'],
                    'bank' => $hotel['bank'],
                    'account_number' => $hotel['account_number'],
                    'branch' => $hotel['branch'],
                    'bank_and_branch' => $hotel['bank_and_branch'],
                    'swift' => $hotel['swift'],
                    'country_code' => $countryCode,
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('✅ Sri Lanka Hotel Details imported successfully!');
        $this->command->info('📊 Total hotels: ' . count($hotels));
    }
}