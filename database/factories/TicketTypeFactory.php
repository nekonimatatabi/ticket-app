<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    /**
     * Define the model's default state. 既定は販売中の券種。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => fake()->randomElement(['一般', '学生', '早割']),
            'price' => fake()->randomElement([0, 1500, 3000]),
            'capacity' => fake()->numberBetween(5, 30),
            'sales_starts_at' => now()->subDay(),
            'sales_ends_at' => now()->addMonth(),
        ];
    }
}
