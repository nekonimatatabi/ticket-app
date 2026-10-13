<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

function orderRowCount(string $html): int
{
    return substr_count($html, 'data-test="order-row"');
}

test('shows the buyer\'s own orders from the newest', function () {
    $buyer = User::factory()->create();
    $oldest = Order::factory()->for($buyer)->for(Event::factory()->published()->state(['title' => '一番古いイベント']))
        ->create(['created_at' => now()->subDays(3)]);
    $newest = Order::factory()->for($buyer)->for(Event::factory()->published()->state(['title' => '一番新しいイベント']))
        ->create(['created_at' => now()->subDay()]);
    $middle = Order::factory()->for($buyer)->for(Event::factory()->published()->state(['title' => '真ん中のイベント']))
        ->create(['created_at' => now()->subDays(2)]);

    $this->actingAs($buyer)
        ->get(route('orders.index'))
        ->assertOk()
        ->assertSeeInOrder(['一番新しいイベント', '真ん中のイベント', '一番古いイベント']);
});

test('does not show other people\'s orders', function () {
    $buyer = User::factory()->create();
    Order::factory()->for($buyer)->for(Event::factory()->published()->state(['title' => '自分のイベント']))->create();
    Order::factory()->for(Event::factory()->published()->state(['title' => '他人のイベント']))->create();

    $this->actingAs($buyer)
        ->get(route('orders.index'))
        ->assertSee('自分のイベント')
        ->assertDontSee('他人のイベント');
});

test('shows the date, the event, the number of tickets, the total, the status, and the detail button', function () {
    $buyer = User::factory()->create();
    $event = Event::factory()->published()->create(['title' => '春のマルシェ', 'starts_at' => '2030-05-03 10:00:00']);
    $order = Order::factory()->for($buyer)->for($event)->paid()->create(['total_amount' => 7500, 'created_at' => '2030-04-01 09:30:00']);
    OrderItem::factory()->for($order)->create(['quantity' => 2]);
    OrderItem::factory()->for($order)->create(['quantity' => 3]);

    $this->actingAs($buyer)
        ->get(route('orders.index'))
        ->assertOk()
        ->assertSee($order->created_at->isoFormat('M月D日(ddd) HH:mm'))
        ->assertSee('春のマルシェ')
        ->assertSee($event->starts_at->isoFormat('M月D日(ddd) HH:mm'))
        ->assertSee('5枚')
        ->assertSee('7,500円')
        ->assertSee('支払い済み')
        ->assertSee(route('orders.show', $order), false);
});

test('shows a badge for each status', function (OrderStatus $status) {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->create();
    $order->forceFill(['status' => $status])->save();

    $this->actingAs($buyer)->get(route('orders.index'))->assertSee($status->label());
})->with(OrderStatus::cases());

test('paginates the orders ten at a time', function () {
    $buyer = User::factory()->create();
    Order::factory()->count(11)->for($buyer)->create();

    $first = $this->actingAs($buyer)->get(route('orders.index'))->assertOk();
    $second = $this->actingAs($buyer)->get(route('orders.index', ['page' => 2]))->assertOk();

    expect(orderRowCount($first->getContent()))->toBe(10)
        ->and(orderRowCount($second->getContent()))->toBe(1);
});

test('tells a buyer without orders where to start', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('orders.index'))
        ->assertOk()
        ->assertSeeHtml('data-test="no-orders"')
        ->assertSee('まだ注文がありません');
});

test('redirects guests to the login page', function () {
    $this->get(route('orders.index'))->assertRedirect(route('login'));
});

test('redirects users with an unverified email address to the verification notice', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('orders.index'))
        ->assertRedirect(route('verification.notice'));
});

test('shows exactly ten orders on one page without a second page', function () {
    $buyer = User::factory()->create();
    Order::factory()->count(10)->for($buyer)->create();

    $first = $this->actingAs($buyer)->get(route('orders.index'))->assertOk();
    $second = $this->actingAs($buyer)->get(route('orders.index', ['page' => 2]))->assertOk();

    expect(orderRowCount($first->getContent()))->toBe(10)
        ->and(orderRowCount($second->getContent()))->toBe(0);
});

test('continues with the oldest order on the second page', function () {
    $buyer = User::factory()->create();
    Order::factory()->for($buyer)->for(Event::factory()->published()->state(['title' => '最古のイベント']))
        ->create(['created_at' => now()->subDays(30)]);
    Order::factory()->count(10)->for($buyer)->create(['created_at' => now()->subDay()]);

    $this->actingAs($buyer)->get(route('orders.index'))->assertDontSee('最古のイベント');
    $this->actingAs($buyer)->get(route('orders.index', ['page' => 2]))->assertSee('最古のイベント');
});

test('orders rows created at the same time by id descending', function () {
    $buyer = User::factory()->create();
    $at = now()->subDay();
    Order::factory()->for($buyer)->for(Event::factory()->published()->state(['title' => '先に作ったイベント']))->create(['created_at' => $at]);
    Order::factory()->for($buyer)->for(Event::factory()->published()->state(['title' => '後に作ったイベント']))->create(['created_at' => $at]);

    $this->actingAs($buyer)
        ->get(route('orders.index'))
        ->assertSeeInOrder(['後に作ったイベント', '先に作ったイベント']);
});

test('does not count other people\'s orders in the pages', function () {
    $buyer = User::factory()->create();
    Order::factory()->count(3)->for($buyer)->create();
    Order::factory()->count(20)->create();

    $first = $this->actingAs($buyer)->get(route('orders.index'))->assertOk();
    $second = $this->actingAs($buyer)->get(route('orders.index', ['page' => 2]))->assertOk();

    expect(orderRowCount($first->getContent()))->toBe(3)
        ->and(orderRowCount($second->getContent()))->toBe(0)
        ->and($first->getContent())->not->toContain('page=2');
});

test('still lists an order whose event has become a draft', function () {
    $buyer = User::factory()->create();
    Order::factory()->for($buyer)->for(Event::factory()->unpublished()->state(['title' => '非公開になったイベント']))->paid()->create();

    $this->actingAs($buyer)
        ->get(route('orders.index'))
        ->assertOk()
        ->assertSee('非公開になったイベント');
});

test('shows 0 tickets for an order without items', function () {
    $buyer = User::factory()->create();
    Order::factory()->for($buyer)->create();

    $this->actingAs($buyer)->get(route('orders.index'))->assertSee('0枚');
});

test('marks the navigation item as current on the list page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('orders.index'))
        ->assertSeeHtml('href="'.route('orders.index').'"')
        ->assertSeeHtml('data-current');
});
