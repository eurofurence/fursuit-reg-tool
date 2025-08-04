<?php

namespace Database\Seeders;

use App\Models\Staff;
use App\Models\RfidTag;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class StaffAndRfidTagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create test staff members
        $staff1 = Staff::create([
            'name' => 'John Cashier',
            'pin_code' => '123456',
            'is_active' => true,
        ]);

        $staff2 = Staff::create([
            'name' => 'Sarah Manager',
            'pin_code' => '654321',
            'is_active' => true,
        ]);

        $staff3 = Staff::create([
            'name' => 'Mike Assistant',
            'pin_code' => '111111',
            'is_active' => false, // Inactive for testing
        ]);

        // Create RFID tags for staff
        RfidTag::create([
            'staff_id' => $staff1->id,
            'content' => '0015212704',
            'name' => 'John\'s Primary Badge',
            'is_active' => true,
        ]);

        RfidTag::create([
            'staff_id' => $staff1->id,
            'content' => '0015212705',
            'name' => 'John\'s Backup Badge',
            'is_active' => true,
        ]);

        RfidTag::create([
            'staff_id' => $staff2->id,
            'content' => '0015212706',
            'name' => 'Sarah\'s Badge',
            'is_active' => true,
        ]);

        RfidTag::create([
            'staff_id' => $staff3->id,
            'content' => '0015212707',
            'name' => 'Mike\'s Badge',
            'is_active' => false, // Inactive badge
        ]);

        $this->command->info('Created ' . Staff::count() . ' staff members with ' . RfidTag::count() . ' RFID tags');
    }
}
