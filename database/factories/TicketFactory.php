<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'code' => (string) Str::ulid(),
        ];
    }

    /**
     * 受付済みのチケット。
     */
    public function checkedIn(?User $by = null): static
    {
        return $this->state(fn (array $attributes) => [
            'checked_in_at' => now(),
            'checked_in_by' => $by->id ?? User::factory(),
        ]);
    }
}
