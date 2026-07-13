<?php
// database/seeders/MalaysiaAttractionsSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MalaysiaAttraction;

class MalaysiaAttractionsSeeder extends Seeder
{
    public function run()
    {
        $attractions = [
            // KL Towers & Views
            ['name' => 'KL Tower Entrance', 'category' => 'Towers & Views', 'portal' => null],
            ['name' => 'Twin Tower Entrance', 'category' => 'Towers & Views', 'portal' => null],
            
            // Museums & Art
            ['name' => 'Illusion 3D Art Museum', 'category' => 'Museums & Art', 'portal' => null],
            ['name' => 'Immersify Kuala Lumpur', 'category' => 'Museums & Art', 'portal' => null],
            ['name' => 'Pinang Peranakan Mansion', 'category' => 'Museums & Art', 'portal' => null],
            ['name' => 'Penang 3D Trick Art Museum', 'category' => 'Museums & Art', 'portal' => null],
            ['name' => 'Wonder Food Museum Penang', 'category' => 'Museums & Art', 'portal' => null],
            
            // Theme Parks
            ['name' => '99 Wonderland Park', 'category' => 'Theme Parks', 'portal' => null],
            ['name' => 'Berjaya Time Square Theme Park', 'category' => 'Theme Parks', 'portal' => null],
            ['name' => 'Sunway Lagoon 6 Parks Entrance', 'category' => 'Theme Parks', 'portal' => null],
            ['name' => 'Sunway Lagoon Quack Express Pass', 'category' => 'Theme Parks', 'portal' => null],
            ['name' => 'Sunway Lagoon Night Park', 'category' => 'Theme Parks', 'portal' => null],
            ['name' => 'Lost World Hot Springs Night Park', 'category' => 'Theme Parks', 'portal' => null],
            ['name' => 'Skytropolis (Indoor Theme Park)', 'category' => 'Theme Parks', 'portal' => null],
            ['name' => 'Skyworld (Out Door Theme Park)', 'category' => 'Theme Parks', 'portal' => null],
            ['name' => 'Skytrek Adventure In Langkawi - Beginner', 'category' => 'Theme Parks', 'portal' => null],
            ['name' => 'Skytrek Adventure In Langkawi - Eagle Trail', 'category' => 'Theme Parks', 'portal' => null],
            ['name' => 'Skytrek Adventure In Langkawi - Advance', 'category' => 'Theme Parks', 'portal' => null],
            
            // Water Parks
            ['name' => 'I - City Water World', 'category' => 'Water Parks', 'portal' => null],
            ['name' => 'Adventure Waterpark Desaru Coast', 'category' => 'Water Parks', 'portal' => null],
            
            // I-City
            ['name' => 'I - City Day Pass - Red Carpet 2', 'category' => 'I-City', 'portal' => null],
            ['name' => 'I City All Day Happiness Pass', 'category' => 'I-City', 'portal' => null],
            
            // Zoo & Wildlife
            ['name' => 'KL Bird Park', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'KLCC Aquaria', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'Zoo Negara - (Admission + Panda Center)', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'Farm In The City', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'Farm In The City - Selangor', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'Crocodile Farm - Explore Combo', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'Under Water World', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'Wild Life Park (Bird Paradise)', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'Entopia By Butterfly Farm Penang', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'Tropical Fruit Farm Penang', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'Penang Bird Park', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'Tropical Spice Garden Penang', 'category' => 'Zoo & Wildlife', 'portal' => null],
            ['name' => 'Butterfly Farm Cameron Highland', 'category' => 'Zoo & Wildlife', 'portal' => null],
            
            // Attractions
            ['name' => 'Space & Time Cube', 'category' => 'Attractions', 'portal' => null],
            ['name' => 'Dino Desert At Monkeys Canopy In Selangor', 'category' => 'Attractions', 'portal' => null],
            ['name' => 'Dream Forest Langkawi', 'category' => 'Attractions', 'portal' => null],
            
            // Boat & Water Activities
            ['name' => 'Pontoon Boat Sightseeing (Joy Cruiser) - Putrajaya', 'category' => 'Boat Activities', 'portal' => null],
            ['name' => 'Fire Flies Show', 'category' => 'Boat Activities', 'portal' => null],
            ['name' => 'Sky Mirror Tour', 'category' => 'Boat Activities', 'portal' => null],
            ['name' => 'Eagle Feeding Selangor', 'category' => 'Boat Activities', 'portal' => null],
            ['name' => 'Blue Tears', 'category' => 'Boat Activities', 'portal' => null],
            ['name' => 'Malacca River Cruise', 'category' => 'Boat Activities', 'portal' => null],
            
            // Hop On Hop Off
            ['name' => 'KL Hop On Hop Off Bus Night', 'category' => 'Hop On Hop Off', 'portal' => null],
            ['name' => 'KL Hop On Hop Off 48 Hours', 'category' => 'Hop On Hop Off', 'portal' => null],
            ['name' => 'KL Hop On Hop Off 24 Hours', 'category' => 'Hop On Hop Off', 'portal' => null],
            
            // Kids
            ['name' => 'Kidzania', 'category' => 'Kids', 'portal' => null],
            
            // Transport
            ['name' => 'KL To SIN (Coach Ticket)', 'category' => 'Transport', 'portal' => null],
            ['name' => 'Cameron Highland To Penang Ticket', 'category' => 'Transport', 'portal' => null],
            
            // Skyline Luge
            ['name' => 'Skyline Luge - Fun Park At Gamuda Luge Garden', 'category' => 'Skyline Luge', 'portal' => null],
            ['name' => 'Skyline Luge - Skyline Luge Gamuda Garden - 4 Rides', 'category' => 'Skyline Luge', 'portal' => null],
            ['name' => 'Skyline Luge Gamuda Garden - 5 Rides', 'category' => 'Skyline Luge', 'portal' => null],
            
            // Genting
            ['name' => '1 Way Cable Car (Skyway) Genting', 'category' => 'Genting', 'portal' => null],
            
            // Penang
            ['name' => 'The Top @ Komtar Observatory', 'category' => 'Penang', 'portal' => null],
            ['name' => 'Rainbow Skywalk (Level 68) (Glass Bridge)', 'category' => 'Penang', 'portal' => null],
            ['name' => 'Rainbow Skywalk + Observatory Deck + Skybridge (New Windows Of The Top)', 'category' => 'Penang', 'portal' => null],
            ['name' => 'The Top Boutique Aquarium', 'category' => 'Penang', 'portal' => null],
            ['name' => 'Rainbow Skywalk + Observatory Deck + Sky Bridge + Jurassic Research Center + Top Boutique Aquarium', 'category' => 'Penang', 'portal' => null],
            ['name' => 'Rainbow Skywalk And Observatory Deck + Sky Bridge (3 Park)', 'category' => 'Penang', 'portal' => null],
            ['name' => 'Funicular Train (Fast Lane) Penang (Train Ticket)', 'category' => 'Penang', 'portal' => null],
            ['name' => 'Penang Hill Train Normal Lane', 'category' => 'Penang', 'portal' => null],
            ['name' => 'Escape Penang', 'category' => 'Penang', 'portal' => null],
            ['name' => 'The Habitat Penang Hill', 'category' => 'Penang', 'portal' => null],
            
            // Langkawi
            ['name' => 'Cable Car - Skycab Admission - Skydome, Skyrex And 3D Art Langkawi', 'category' => 'Langkawi', 'portal' => null],
            ['name' => 'Sky Bridge @ Cable Car', 'category' => 'Langkawi', 'portal' => null],
            ['name' => 'Cable Car - Glass Bottom Gondola (Skydome, Skyrex And 3D Art Langkawi)', 'category' => 'Langkawi', 'portal' => null],
            ['name' => 'Cable Car - Skycab Admission + Skywalk + Skybridge', 'category' => 'Langkawi', 'portal' => null],
            ['name' => 'Cable Car - Skycab X Eagle Nest Skywalk', 'category' => 'Langkawi', 'portal' => null],
            ['name' => 'Cable - Skycab Admission + Express Lane', 'category' => 'Langkawi', 'portal' => null],
            
            // Legoland
            ['name' => 'Legoland 1-Day Theme Park Ticket', 'category' => 'Legoland', 'portal' => null],
            ['name' => 'Legoland 1 Day Sealife Park', 'category' => 'Legoland', 'portal' => null],
            ['name' => 'Legoland 1 Day Combo (Theme Park + Sealife)', 'category' => 'Legoland', 'portal' => null],
            ['name' => 'Legoland 1 Day Combo (Theme Park & Sea Life & Water Park)', 'category' => 'Legoland', 'portal' => null],
            
            // Malacca
            ['name' => 'Museum Samudra', 'category' => 'Malacca', 'portal' => null],
            ['name' => 'Menara Taming Sari', 'category' => 'Malacca', 'portal' => null],
            
            // Cameron Highland
            ['name' => 'Cactus Valley', 'category' => 'Cameron Highland', 'portal' => null],
            ['name' => 'Rose Valley Cameron Highland', 'category' => 'Cameron Highland', 'portal' => null],
            
            // Wetland
            ['name' => 'Wetland Studios Putrajaya', 'category' => 'Wetland', 'portal' => null],
        ];

        foreach ($attractions as $attraction) {
            MalaysiaAttraction::create($attraction);
        }
    }
}