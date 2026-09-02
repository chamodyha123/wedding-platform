<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Wedding Function Hotels',
            'Honeymoon Hotels',
            'Photography & Videography',
            'Wedding Cars / Vehicle Rental',
            'Jaya Mangala Gatha Services',
            'Catering Services',
            'Wedding Decoration',
            'Flower Decoration',
            'Wedding Cakes',
            'DJ & Musical Bands',
            'Sound & Lighting',
            'Wedding Cards & Invitation Services',
            'Buses & Vans',
            'Dancing Teams',
            'Traditional Dancing - Magul Bera / Wes Natum',
            'Wine & Beverage Stores',
            'Nakath Creation Services',
            'Wedding Planning',
            'Bridal Salons',
            'Groom Salons',
            'Bride & Groom Salons',
            'Bridal Clothing',
            'Groom Clothing',
            'Bride & Groom Clothing',
            'Poru & Setiback Services',
            'Jewellery Shops',
            'Gift Centers',
        ];

        foreach ($categories as $name) {
            ServiceCategory::firstOrCreate(
                ['name' => $name],
                [
                    'slug' => Str::slug($name),
                    'is_active' => true,
                ]
            );
        }
    }
}