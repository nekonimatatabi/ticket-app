<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Livewire\Livewire;

test('a guest chooses tickets, logs in, returns to the purchase page, and completes the order', function () {
    $user = User::factory()->create();
    $event = Event::factory()->published()->create(['title' => '春のマルシェ']);
    $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000, 'capacity' => 20]);

    // 未ログインで枚数を選んで、購入手続きへ進む
    Livewire::test('pages::events.show', ['event' => $event])
        ->set("quantities.{$ticketType->id}", 2)
        ->call('continueToPurchase')
        ->assertRedirect(route('events.purchase', $event));

    // 購入手続きはログインが必要なので、ログイン画面に飛ばされる
    $this->get(route('events.purchase', $event))->assertRedirect(route('login'));

    // ログインすると、購入手続きに戻る
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('events.purchase', $event));

    // 選んだ枚数が残っていて、表と合計が出る
    $this->get(route('events.purchase', $event))
        ->assertOk()
        ->assertSee('一般')
        ->assertSee('2枚')
        ->assertSee('6,000円');

    // 支払いへ進むと、注文（支払い待ち）が作られて、完了画面へ進む
    Livewire::test('pages::events.purchase', ['event' => $event])
        ->call('proceedToPayment')
        ->assertRedirect(route('orders.complete', Order::sole()));

    expect(Order::sole())
        ->user_id->toBe($user->id)
        ->status->toBe(OrderStatus::Pending)
        ->total_amount->toBe(6000);

    $this->get(route('orders.complete', Order::sole()))
        ->assertOk()
        ->assertSee('注文を受け付けました（支払いは平日課題で実装）')
        ->assertSee('6,000円');
});

test('a logged-in user can buy from the event page to the completion page', function () {
    $user = User::factory()->create();
    $event = Event::factory()->published()->create();
    $general = TicketType::factory()->for($event)->create(['price' => 1000]);
    $student = TicketType::factory()->for($event)->create(['price' => 500]);

    $this->actingAs($user);

    Livewire::test('pages::events.show', ['event' => $event])
        ->set("quantities.{$general->id}", 1)
        ->set("quantities.{$student->id}", 3)
        ->call('continueToPurchase')
        ->assertRedirect(route('events.purchase', $event));

    $this->get(route('events.purchase', $event))->assertOk()->assertSee('2,500円');

    Livewire::test('pages::events.purchase', ['event' => $event])
        ->call('proceedToPayment')
        ->assertRedirect(route('orders.complete', Order::sole()));

    expect(Order::sole()->total_amount)->toBe(2500);
});
