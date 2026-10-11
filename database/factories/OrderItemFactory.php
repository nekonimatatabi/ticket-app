<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'ticket_type_id' => TicketType::factory(),
            'quantity' => fake()->numberBetween(1, 4),
            'unit_price' => fake()->randomElement([0, 1500, 3000]),
        ];
    }
}
