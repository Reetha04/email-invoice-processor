<?php
// database/seeders/SingaporeAttractionsSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SingaporeAttraction;

class SingaporeAttractionsSeeder extends Seeder
{
    public function run()
    {
        $attractions = [
            // Art & Science Museum
            ['name' => 'Art & Science Museum Future World: Where Art Meets Science - Peak [Standard Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Peak', 'ticket_type' => 'Tourist'],
            ['name' => 'Art & Science Museum Future World: Where Art Meets Science - Off Peak [Standard Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Off Peak', 'ticket_type' => 'Tourist'],
            ['name' => 'Art & Science Museum: Another World Is Possible - Off Peak [Standard Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Off Peak', 'ticket_type' => 'Tourist'],
            ['name' => 'Art & Science Museum: Another World Is Possible - Peak [Standard Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Peak', 'ticket_type' => 'Tourist'],
            ['name' => 'Art & Science Museum: Insects: Microsculptures Magnified - Off Peak [Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Off Peak', 'ticket_type' => 'Tourist'],
            ['name' => 'Art & Science Museum: Insects: Microsculptures Magnified - Peak [Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Peak', 'ticket_type' => 'Tourist'],
            ['name' => 'Art & Science Museum: Nox: Confessions Of A Machine - Off Peak [Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Off Peak', 'ticket_type' => 'Tourist'],
            ['name' => 'Art & Science Museum: Nox: Confessions Of A Machine - Peak [Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Peak', 'ticket_type' => 'Tourist'],
            ['name' => '[Two Exhibitions] Insects: Microsculptures Magnified (IMM) + Nox: Confessions Of A Machine (NOX) - Off Peak [Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Off Peak', 'ticket_type' => 'Tourist'],
            ['name' => '[Two Exhibitions] Insects: Microsculptures Magnified (IMM) + Nox: Confessions Of A Machine (NOX) - Off Peak [Local]', 'category' => 'Art & Science Museum', 'peak_type' => 'Off Peak', 'ticket_type' => 'Local'],
            ['name' => '[Two Exhibitions] Insects: Microsculptures Magnified (IMM) + Nox: Confessions Of A Machine (NOX) - Peak [Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Peak', 'ticket_type' => 'Tourist'],
            ['name' => '[Two Exhibitions] Insects: Microsculptures Magnified (IMM) + Nox: Confessions Of A Machine (NOX) - Peak [Local]', 'category' => 'Art & Science Museum', 'peak_type' => 'Peak', 'ticket_type' => 'Local'],
            ['name' => '[Three Exhibitions] Teamlab Future World (FW) + Insects: Microsculptures Magnified (IMM) + Nox: Confessions Of A Machine (NOX) - Off Peak [Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Off Peak', 'ticket_type' => 'Tourist'],
            ['name' => '[Three Exhibitions] Teamlab Future World (FW) + Insects: Microsculptures Magnified (IMM) + Nox: Confessions Of A Machine (NOX) - Off Peak [Local]', 'category' => 'Art & Science Museum', 'peak_type' => 'Off Peak', 'ticket_type' => 'Local'],
            ['name' => '[Three Exhibitions] Teamlab Future World (FW) + Insects: Microsculptures Magnified (IMM) + Nox: Confessions Of A Machine (NOX) - Peak [Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Peak', 'ticket_type' => 'Tourist'],
            ['name' => '[Three Exhibitions] Teamlab Future World (FW) + Insects: Microsculptures Magnified (IMM) + Nox: Confessions Of A Machine (NOX) - Peak [Local]', 'category' => 'Art & Science Museum', 'peak_type' => 'Peak', 'ticket_type' => 'Local'],
            ['name' => 'Flesh And Bones: The Art Of Anatomy - Peak [Local]', 'category' => 'Art & Science Museum', 'peak_type' => 'Peak', 'ticket_type' => 'Local'],
            ['name' => 'Flesh And Bones: The Art Of Anatomy - Peak [Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Peak', 'ticket_type' => 'Tourist'],
            ['name' => 'Flesh And Bones: The Art Of Anatomy - Off Peak [Local]', 'category' => 'Art & Science Museum', 'peak_type' => 'Off Peak', 'ticket_type' => 'Local'],
            ['name' => 'Flesh And Bones: The Art Of Anatomy - Off Peak [Tourist]', 'category' => 'Art & Science Museum', 'peak_type' => 'Off Peak', 'ticket_type' => 'Tourist'],
            
            // Museums & Discovery Centers
            ['name' => 'Singapore Discovery Center - Permanent Exhibits Gallery (Through The Lens Of Time & Sandbox)', 'category' => 'Museum', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Singapore Discovery Center - Black Lake Facility (Escape Room)', 'category' => 'Museum', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Singapore Navy Museum', 'category' => 'Museum', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Singapore Art Museum (SAM)', 'category' => 'Museum', 'peak_type' => null, 'ticket_type' => null],
            
            // Science Centre
            ['name' => 'Omni Theatre Movie Admission', 'category' => 'Science Centre', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Science Centre + Omni Theatre Movie', 'category' => 'Science Centre', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Science Centre Admission', 'category' => 'Science Centre', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Science Center + Kidsstop', 'category' => 'Science Centre', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Kidsstop Admission At Science Centre', 'category' => 'Science Centre', 'peak_type' => null, 'ticket_type' => null],
            
            // Tours & Cruises
            ['name' => 'Singapore Ducktours (English Tour) - Peak', 'category' => 'Tour', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Singapore Ducktours (English Tour) - Off Peak (Monday - Friday)', 'category' => 'Tour', 'peak_type' => 'Off Peak', 'ticket_type' => null],
            ['name' => 'Captain Explorer DUKW Tour Open Ticket', 'category' => 'Tour', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Hop On Hop Off - Singapore Big Bus Tour', 'category' => 'Tour', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Singapore River Cruise', 'category' => 'Tour', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Singapore River Cruise By WaterB', 'category' => 'Tour', 'peak_type' => null, 'ticket_type' => null],
            
            // Gardens & Parks
            ['name' => 'Singapore Flyer + Time Capsule Tickets', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Gardens By The Bay (Flower Dome + Cloud Forest - Jurassic World The Exhibition) Ticket Only', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Flower Dome & Cloud Forest Featuring Jurassic World: The Experience (Includes Supertree Observatory)', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Gardens By The Bay: Supertree Observatory', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => '[Promo] Supertree Observatory (Entry Between 9AM And 12PM)', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => '[50% Off Promo] My Little Pony At Floral Fantasy (Entry Between 10AM And 3PM)', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Gardens By The Bay: My Little Pony At Floral Fantasy Admission', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Canopy Park', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Mastercard® Canopy Bridge + Canopy Park (Standard - Travel Agent Open Dated)', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Hedge Maze Including Canopy Park', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Mirror Maze Including Canopy Park', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Walking Net Including Canopy Park', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Bouncing Net Including Canopy Park', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Jewel Changi Airport - Canopy Park Standard - Travel Agent Open Dated', 'category' => 'Gardens & Parks', 'peak_type' => null, 'ticket_type' => null],
            
            // Attractions
            ['name' => 'Changi Experience Studio', 'category' => 'Attraction', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Marina Bay Sands Skypark - Admission Ticket (Non-Peak) (QR Code - Direct Entry)', 'category' => 'Attraction', 'peak_type' => 'Non-Peak', 'ticket_type' => null],
            ['name' => 'Marina Bay Sands Skypark - Admission Ticket (Peak) (QR Code - Direct Entry)', 'category' => 'Attraction', 'peak_type' => 'Peak', 'ticket_type' => null],
            
            // Snow & Ice
            ['name' => '2 Hour Snow Play + Ice Hotel Gallery + 1 Bumper Car - Valid For 6 Months', 'category' => 'Snow & Ice', 'peak_type' => null, 'ticket_type' => null],
            ['name' => '1 Hour Snow Play With Dyed Ice Playground + 1 Bumper Car - Valid For 6 Months', 'category' => 'Snow & Ice', 'peak_type' => null, 'ticket_type' => null],
            ['name' => '1 Hour Snow Play With Dyed Ice Playground', 'category' => 'Snow & Ice', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Ice Cream Museum - General Admission', 'category' => 'Snow & Ice', 'peak_type' => null, 'ticket_type' => null],
            
            // Universal Studios
            ['name' => 'Universal Studios - Admission (Standard Dates) - Peak - Saturday, Sunday And Public Holidays', 'category' => 'Universal Studios', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Universal Studios - $10 Meal Or Retail Voucher', 'category' => 'Universal Studios', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Universal Studios Singapore Universal Express (Exclusive Of Minion Land Rides) - Tier 4', 'category' => 'Universal Studios', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Universal Studios Singapore Admission Ticket + Early Entry + Minion Thematic Set Meal + Retail Voucher', 'category' => 'Universal Studios', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Universal Studios Singapore Halloween Horror Nights - Peak', 'category' => 'Universal Studios', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Universal Studios Singapore Space Killers Carnival Game Voucher (Available Before 12PM)', 'category' => 'Universal Studios', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Universal Studios Singapore Halloween Horror Nights - Non Peak', 'category' => 'Universal Studios', 'peak_type' => 'Non-Peak', 'ticket_type' => null],
            ['name' => 'Multipark Promotion [USS, SGO, ACW] - Peak', 'category' => 'Universal Studios', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Multipark Promotion [USS, SGO, ACW] - Non Peak', 'category' => 'Universal Studios', 'peak_type' => 'Non-Peak', 'ticket_type' => null],
            
            // Oceanarium & Water Parks
            ['name' => 'Singapore Oceanarium Admission Ticket (Monday To Friday)', 'category' => 'Oceanarium', 'peak_type' => 'Off Peak', 'ticket_type' => null],
            ['name' => 'Singapore Oceanarium Admission Ticket (Saturday And Sunday)', 'category' => 'Oceanarium', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Singapore Oceanarium And Dolphin Connection (Former Dolphin Adventure)', 'category' => 'Oceanarium', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Singapore Oceanarium + Dolphin Exploration (Former Dolphin Encounter)', 'category' => 'Oceanarium', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Singapore Oceanarium + Dolphin Immersion (Former Dolphin Discovery)', 'category' => 'Oceanarium', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Singapore Oceanarium W/ $5 Asian Food Hall Voucher (At Weave)', 'category' => 'Oceanarium', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Animal Spotlight: Seahorses Programme [English]', 'category' => 'Oceanarium', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Adventure Cove Waterpark - Admission Ticket', 'category' => 'Water Park', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Adventure Cove Park And Dolphin Observer - Peak', 'category' => 'Water Park', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Adventure Cove Park And Dolphin Observer - Non Peak', 'category' => 'Water Park', 'peak_type' => 'Non-Peak', 'ticket_type' => null],
            ['name' => 'Adventure Cove Waterpark + Dolphin Immersion - English', 'category' => 'Water Park', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Adventure Cove Waterpark + Dolphin Exploration [Peak] (Former Dolphin Encounter)', 'category' => 'Water Park', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Adventure Cove Waterpark + Dolphin Exploration - English', 'category' => 'Water Park', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Adventure Cove Waterpark + Dolphin Connection [Peak] (Former Dolphin Adventure)', 'category' => 'Water Park', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Adventure Cove Waterpark + Dolphin Connection - English', 'category' => 'Water Park', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Adventure Cove Park Admission + DXE Set Meal', 'category' => 'Water Park', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Adventure Cove Waterpark™ - Standard Admission + $25 Meal Voucher', 'category' => 'Water Park', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Adventure Cove Waterpark + Dolphin Observer - English', 'category' => 'Water Park', 'peak_type' => null, 'ticket_type' => null],
            
            // Sentosa
            ['name' => 'Sentosa 4D Adventureland - 4-In-1 Combo (One-Time Admission)', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Sentosa 4D Adventureland - 4D Adventure Any 2 Rides (One-Time Admission)', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Sentosa 4D Adventureland - 4D Adventure Any 3 Rides (One-Time Admission)', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Sentosa - Fun Discovery Pass 95 Tokens', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Sentosa - Fun Discovery Pass 130 Tokens', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Madame Tussauds Singapore 4 In 1 (Images Of Singapore + Madame Tussauds + Ultimate Film Star Experience + Spirit Of Singapore Boat Ride)', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Madame Tussauds Singapore 5 In 1 + Digiphoto + VR [Adult/Child]', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Madame Tussauds Singapore 5 In 1 (Images Of Singapore + Madame Tussauds + Ultimate Film Star Experience + Spirit Of Singapore Boat Ride + Digiphoto + Marvel4D)', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Skypark Sentosa Tandem Bungy Jump', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Skypark Sentosa Skybridge + $15 F&B Credit', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Skypark Sentosa Skybridge', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Skypark Sentosa Giant Swing', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Sentosa - Island Admission (Via Express)', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Trickeye Museum', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Skyhelix Sentosa - Direct Entry Admissions', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Sentosa - Cable Car – Mount Faber Line Round Trip', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Sentosa - Cable Car Sky Pass - Round Trip (Direct Entry)', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Skyorb Skypass Premium Round Trip', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Sentosa Island Bus Tour', 'category' => 'Sentosa', 'peak_type' => null, 'ticket_type' => null],
            
            // iFly & Skyline Luge
            ['name' => 'iFly Singapore - The Challenge Package (Peak) – Tourist', 'category' => 'iFly', 'peak_type' => 'Peak', 'ticket_type' => 'Tourist'],
            ['name' => 'iFly Singapore - The Challenge Package - Off Peak (Tourist)', 'category' => 'iFly', 'peak_type' => 'Off Peak', 'ticket_type' => 'Tourist'],
            ['name' => 'Skyline Luge Singapore [Peak] - 4 Luge & 4 Skyride Combo', 'category' => 'Skyline Luge', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Skyline Luge Singapore [Peak] - 3 Luge & 3 Skyride Combo', 'category' => 'Skyline Luge', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Skyline Luge Singapore - 2 Luge [Peak] & 2 Skyride Combo + Luge Merchandise', 'category' => 'Skyline Luge', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Skyline Luge Singapore - [Non Peak] 4 Luge & 4 Skyride Combo - Flexi', 'category' => 'Skyline Luge', 'peak_type' => 'Non-Peak', 'ticket_type' => null],
            ['name' => 'Skyline Luge Singapore - [Non Peak] 3 Luge & 3 Skyride Combo - Flexi', 'category' => 'Skyline Luge', 'peak_type' => 'Non-Peak', 'ticket_type' => null],
            ['name' => 'Skyline Luge Singapore - [Non Peak] 2 Luge & 2 Skyride Combo + Luge Merchandise', 'category' => 'Skyline Luge', 'peak_type' => 'Non-Peak', 'ticket_type' => null],
            
            // Mega Adventure
            ['name' => 'Megazip & Jump @ Mega Adventure Park', 'category' => 'Mega Adventure', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Megazip Tandem @ Mega Adventure Park', 'category' => 'Mega Adventure', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Megazip, Climb & Jump @ Mega Adventure Park', 'category' => 'Mega Adventure', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Megazip, Climb, Jump & Sail @ Mega Adventure Park', 'category' => 'Mega Adventure', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'World Famous: Megazip (2 Rides) @ Mega Adventure Park', 'category' => 'Mega Adventure', 'peak_type' => null, 'ticket_type' => null],
            
            // Perfume Making
            ['name' => 'Perfume Making Experience 100ML @Scentopia (Weekend)', 'category' => 'Perfume Making', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Perfume Making 50ML @Scentopia (Weekend)', 'category' => 'Perfume Making', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Perfume Making Experience 100ML @Scentopia (Weekdays)', 'category' => 'Perfume Making', 'peak_type' => 'Off Peak', 'ticket_type' => null],
            ['name' => 'Perfume Making 30ML @Scentopia', 'category' => 'Perfume Making', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Perfume Making 50ML @Scentopia (Weekdays)', 'category' => 'Perfume Making', 'peak_type' => 'Off Peak', 'ticket_type' => null],
            
            // Yacht Cruises
            ['name' => 'YachtCruisesG Yacht + Kusu Island (2.5Hr)', 'category' => 'Yacht Cruise', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'YachtCruisesG Cruise & Dine', 'category' => 'Yacht Cruise', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'YachtCruisesG City Skyline Cruise Standard (1Hr)', 'category' => 'Yacht Cruise', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'YachtCruisesG BBQ Dinner Cruise (2.5Hr)', 'category' => 'Yacht Cruise', 'peak_type' => null, 'ticket_type' => null],
            
            // Zoo & Wildlife
            ['name' => 'Singapore Zoo - Tourist Admission + Tram', 'category' => 'Zoo & Wildlife', 'peak_type' => null, 'ticket_type' => 'Tourist'],
            ['name' => 'Singapore Zoo - Breakfast In The Wild', 'category' => 'Zoo & Wildlife', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Night Safari - Night Safari Admission + Creatures Of The Night', 'category' => 'Zoo & Wildlife', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Night Safari - Tourist – Admission With Tram', 'category' => 'Zoo & Wildlife', 'peak_type' => null, 'ticket_type' => 'Tourist'],
            ['name' => 'Night Safari - Admission + Multi-Lingual Tram (Tourist)', 'category' => 'Zoo & Wildlife', 'peak_type' => null, 'ticket_type' => 'Tourist'],
            ['name' => 'Night Safari - Priority Tram Boarding', 'category' => 'Zoo & Wildlife', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Night Safari - Multi-Lingual Tram At Night Safari', 'category' => 'Zoo & Wildlife', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Bird Paradise - Standard Admission (Tourist) (Valid From October 2nd Onwards)', 'category' => 'Zoo & Wildlife', 'peak_type' => null, 'ticket_type' => 'Tourist'],
            ['name' => 'River Wonders - Tourist – Admission Only', 'category' => 'Zoo & Wildlife', 'peak_type' => null, 'ticket_type' => 'Tourist'],
            ['name' => 'River Wonders - Amazon River Quest Boat Ride (Admission Not Included)', 'category' => 'Zoo & Wildlife', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Rainforest Wild Asia - Tourist - Admission Only', 'category' => 'Zoo & Wildlife', 'peak_type' => null, 'ticket_type' => 'Tourist'],
            
            // Combo Passes - Zoo
            ['name' => '2-Parks Destination Pass - Night Safari + Rainforest Wild Asia (Tourists)', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => 'Tourist'],
            ['name' => '2-Parks Destination Pass - Night Safari + Bird Paradise', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => null],
            ['name' => '2-Parks Destination Pass - Night Safari + River Wonders', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => null],
            ['name' => '2-Parks Destination Pass - Night Safari + Singapore Zoo', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'MWR 5-Parks Destination Pass TA', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'MWR 4-Parks Destination Pass TA', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Explorer Pass (3 In 1) - Singapore Zoo + River Wonders + Bird Paradise Admission', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Explorer Pass (3 In 1) - Rainforest Wild Asia + River Wonders + Bird Paradise Admission', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => null],
            ['name' => '4-Park Destination Pass [Night Safari, Singapore Zoo, Bird Paradise And Rainforest Wild Asia]', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => null],
            ['name' => '5-Park Destination Pass [Rainforest Wild Asia, Singapore Zoo, River Wonders, Bird Paradise And Night Safari]', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Park Hopper 4 In 1 (Night Safari + Rainforest Wild Asia + Zoo + Bird Paradise)', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Park Hopper 5 In 1 (Night Safari + Rainforest + Zoo + Bird Paradise + River Wonder)', 'category' => 'Combo Pass', 'peak_type' => null, 'ticket_type' => null],
            
            // Combo Passes - Universal Studios
            ['name' => 'Universal Studios Singapore [Peak] + Night Safari', 'category' => 'Combo Pass', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Universal Studios Singapore [Peak] + Singapore Zoo', 'category' => 'Combo Pass', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Universal Studios Singapore [Peak] + Adventure Water Cove Admission Ticket', 'category' => 'Combo Pass', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Universal Studios Singapore [Non Peak] + S.E.A Aquarium Admission', 'category' => 'Combo Pass', 'peak_type' => 'Non-Peak', 'ticket_type' => null],
            
            // Shows
            ['name' => 'Wings Of Time Open Dated (Standard) + Gold International Buffet', 'category' => 'Show', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Wings Of Time – Premium (Open Dated)', 'category' => 'Show', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Wings Of Time – Standard (Open Dated) - 7.40 PM/8.40 PM', 'category' => 'Show', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Wings Of Time - Standard (Open Dated) + Cable Car (Round Trip)', 'category' => 'Show', 'peak_type' => null, 'ticket_type' => null],
            
            // Water Parks
            ['name' => 'Wild Wild Wet - Day Pass (Peak) (Sat - Sun, Public Holiday)', 'category' => 'Water Park', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Wild Wild Wet - Day Pass (Off Peak) (Monday To Friday)', 'category' => 'Water Park', 'peak_type' => 'Off Peak', 'ticket_type' => null],
            
            // Experiences
            ['name' => 'Dinoventure - VR', 'category' => 'Experience', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Premium Champagne Experience', 'category' => 'Experience', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Singapore Sling Experience', 'category' => 'Experience', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Stand Up Paddle Board - Ola Beach Club - 60Min', 'category' => 'Experience', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Hydrodash Admission Ticket', 'category' => 'Experience', 'peak_type' => null, 'ticket_type' => null],
            
            // Harry Potter
            ['name' => 'Harry Potter Visions Of Magic - Dated With $10 Retail Voucher - Off Peak', 'category' => 'Harry Potter', 'peak_type' => 'Off Peak', 'ticket_type' => null],
            ['name' => 'Harry Potter Visions Of Magic - Dated With $10 Retail Voucher - Peak', 'category' => 'Harry Potter', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Harry Potter Visions Of Magic - Dated With $10 Retail Voucher - Super Peak', 'category' => 'Harry Potter', 'peak_type' => 'Super Peak', 'ticket_type' => null],
            
            // National Gallery
            ['name' => 'National Gallery Singapore (Blackout Dates Applicable)', 'category' => 'Gallery', 'peak_type' => null, 'ticket_type' => null],
            
            // Van Gogh Exhibitions
            ['name' => 'Van Gogh Inside: Love, Vincent – Immersive Exhibition Ticket (Valley Season)', 'category' => 'Exhibition', 'peak_type' => 'Valley Season', 'ticket_type' => null],
            ['name' => 'Van Gogh Inside: Love, Vincent – Immersive Exhibition Ticket (Normal Season)', 'category' => 'Exhibition', 'peak_type' => 'Normal Season', 'ticket_type' => null],
            
            // Gustav Klimt
            ['name' => 'Gustav Klimt: Timeless Beauty – Immersive Exhibition Ticket (Valley Season)', 'category' => 'Exhibition', 'peak_type' => 'Valley Season', 'ticket_type' => null],
            ['name' => 'Gustav Klimt: Timeless Beauty – Immersive Exhibition Ticket (Normal Season)', 'category' => 'Exhibition', 'peak_type' => 'Normal Season', 'ticket_type' => null],
            
            // Monet
            ['name' => 'Monet Inside: An Immersive Exhibition – Singapore (Valley Season)', 'category' => 'Exhibition', 'peak_type' => 'Valley Season', 'ticket_type' => null],
            ['name' => 'Monet Inside: An Immersive Exhibition – Singapore (Normal Season)', 'category' => 'Exhibition', 'peak_type' => 'Normal Season', 'ticket_type' => null],
            
            // Puzzle Hunt
            ['name' => 'Ransack Puzzle Hunt: The Meltdown Menace (Minimum 4 And Maximum 4 Pax)', 'category' => 'Puzzle Hunt', 'peak_type' => null, 'ticket_type' => null],
            
            // Splash Tribe
            ['name' => 'Splash Tribe - S$100 Nett F&B Credits + Cover Charge For 4 Pax [Dining Table]', 'category' => 'Splash Tribe', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Splash Tribe - S$100 Nett F&B Credits + Cover Charge For 4 Pax [Daybed]', 'category' => 'Splash Tribe', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Splash Tribe - S$100 Nett F&B Credits + Cover Charge For 4 Pax [Sun Lounger]', 'category' => 'Splash Tribe', 'peak_type' => null, 'ticket_type' => null],
            ['name' => '+Twelve Beach Daybed Escape – 1 Daybed Session + S$150 F&B Credits', 'category' => 'Splash Tribe', 'peak_type' => null, 'ticket_type' => null],
            
            // Vroomtown
            ['name' => 'Vroomtown Driving City - 1-Hour Vroom Learner Pass (1 Pass Admission)', 'category' => 'Vroomtown', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Vroomtown Driving City - 1-Hour Vroom Learner Pass (2 Passes Admission)', 'category' => 'Vroomtown', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Vroomtown Driving City - 1-Hour Vroom Learner Pass (3 Passes Admission)', 'category' => 'Vroomtown', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Vroomtown Driving City - 1-Hour Vroom Learner Pass (4 Passes Admission)', 'category' => 'Vroomtown', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Vroomtown Driving City - 1-Hour Vroom Learner Pass (5 Passes Admission)', 'category' => 'Vroomtown', 'peak_type' => null, 'ticket_type' => null],
            
            // Classic Hawker
            ['name' => 'Classic Hawker Experience Standard Admission', 'category' => 'Food Experience', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Classic Hawker Experience + Chef\'s Table – Workshop Of The Day [Min 1 - Max 15]', 'category' => 'Food Experience', 'peak_type' => null, 'ticket_type' => null],
            
            // Titanic
            ['name' => 'Titanic: An Immersive Voyage In Singapore - Standard Admission - Valley Season', 'category' => 'Exhibition', 'peak_type' => 'Valley Season', 'ticket_type' => null],
            ['name' => 'Titanic: An Immersive Voyage In Singapore - Standard Admission - Normal Season', 'category' => 'Exhibition', 'peak_type' => 'Normal Season', 'ticket_type' => null],
            ['name' => 'Titanic: An Immersive Voyage In Singapore - Standard Admission - Peak Season', 'category' => 'Exhibition', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Titanic: An Immersive Voyage In Singapore - VIP Admission - Valley Season', 'category' => 'Exhibition', 'peak_type' => 'Valley Season', 'ticket_type' => 'VIP'],
            ['name' => 'Titanic: An Immersive Voyage In Singapore - VIP Admission - Normal Season', 'category' => 'Exhibition', 'peak_type' => 'Normal Season', 'ticket_type' => 'VIP'],
            ['name' => 'Titanic: An Immersive Voyage In Singapore - VIP Admission - Peak Season', 'category' => 'Exhibition', 'peak_type' => 'Peak', 'ticket_type' => 'VIP'],
            
            // The Little Prince
            ['name' => 'The Little Prince: The Journey Of Stars – Tourist Admission - Normal Season', 'category' => 'Exhibition', 'peak_type' => 'Normal Season', 'ticket_type' => 'Tourist'],
            ['name' => 'The Little Prince: The Journey Of Stars – Tourist Admission - Valley Season', 'category' => 'Exhibition', 'peak_type' => 'Valley Season', 'ticket_type' => 'Tourist'],
            
            // Kids Parks
            ['name' => 'Pororo Park Singapore - 3 Hours Ticket - Family (1 Adult + 1 Child)', 'category' => 'Kids Park', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Pororo Park Singapore - 2 Hours Ticket - Family (1 Adult + 1 Child)', 'category' => 'Kids Park', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Tayo Station - 3-Hour Session (Non-Peak) - Family (1 Adult + 1 Child)', 'category' => 'Kids Park', 'peak_type' => 'Non-Peak', 'ticket_type' => null],
            ['name' => 'Tayo Station - 3-Hour Session (Peak) - Family (1 Adult + 1 Child)', 'category' => 'Kids Park', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Tayo Station - 2-Hour Session (Non-Peak) - Family (1 Adult + 1 Child)', 'category' => 'Kids Park', 'peak_type' => 'Non-Peak', 'ticket_type' => null],
            ['name' => 'Tayo Station - 2-Hour Session (Peak) - Family (1 Adult + 1 Child)', 'category' => 'Kids Park', 'peak_type' => 'Peak', 'ticket_type' => null],
            
            // Superpark
            ['name' => 'Superpark Singapore - Super Pulse (Peak) 2 Hour Playtime', 'category' => 'Superpark', 'peak_type' => 'Peak', 'ticket_type' => null],
            ['name' => 'Superpark Singapore - Super Day Pass (Non-Peak) Unlimited Playtime', 'category' => 'Superpark', 'peak_type' => 'Non-Peak', 'ticket_type' => null],
            
            // Hidden Holland Village
            ['name' => 'Hidden Holland Village (Single Pax)', 'category' => 'Hidden Holland Village', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Hidden Holland Village (Group Of 2 Pax)', 'category' => 'Hidden Holland Village', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Hidden Holland Village (Group Of 3 Pax)', 'category' => 'Hidden Holland Village', 'peak_type' => null, 'ticket_type' => null],
            
            // Albatross
            ['name' => 'Albatross Ho-Ho Speedboat Adventures (Unlimited Day Pass)', 'category' => 'Albatross', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Albatross Ho-Ho Speedboat Adventures (Unlimited Day Pass) + $25 Tall Ship Café Voucher', 'category' => 'Albatross', 'peak_type' => null, 'ticket_type' => null],
            ['name' => 'Albatross Ho-Ho Speedboat Adventures (Unlimited Day Pass) + $50 Tall Ship Café Voucher', 'category' => 'Albatross', 'peak_type' => null, 'ticket_type' => null],
        ];

        foreach ($attractions as $attraction) {
            SingaporeAttraction::create($attraction);
        }
    }
}