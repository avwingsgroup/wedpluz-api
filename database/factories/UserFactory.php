<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => $first.' '.$last,
            'username' => strtolower(preg_replace('/[^a-z0-9]/i', '', $first)).fake()->unique()->numberBetween(100, 999999),
            'email' => fake()->unique()->safeEmail(),
            'date_of_birth' => fake()->dateTimeBetween('-50 years', '-19 years')->format('Y-m-d'),
            'password' => 'secret123',
            'email_verified_at' => now(),
            'profile_completed' => true,
            'interests_completed' => true,
            'city' => 'Lagos',
            'country_code' => 'NG',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null, 'profile_completed' => false, 'interests_completed' => false]);
    }
}
