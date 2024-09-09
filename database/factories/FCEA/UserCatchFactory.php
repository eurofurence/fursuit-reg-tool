<?php


namespace Database\Factories\FCEA;

use App\Models\FCEA\UserCatch;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class UserCatchFactory extends Factory
{
    protected $model = UserCatch::class;

    public function definition(): array
    {
        return [
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),

            'user_id' => User::factory(),
            'fursuit_id' => Fursuit::factory(),
        ];
    }
}
