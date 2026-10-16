<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * 注文を、指定した状態・明細つきで作る。明細は [券種, 枚数, 単価] の配列。合計は明細から計算する。
 *
 * @param  array<int, array{0: TicketType, 1: int, 2: int}>  $lines
 */
function orderOf(Event $event, OrderStatus $status, array $lines, array $attributes = []): Order
{
    $order = Order::factory()->for($event)->create([
        'total_amount' => collect($lines)->sum(fn (array $line) => $line[1] * $line[2]),
        ...$attributes,
    ]);
    $order->forceFill([
        'status' => $status,
        'paid_at' => $attributes['paid_at'] ?? ($status === OrderStatus::Paid ? now() : null),
    ])->save();

    foreach ($lines as [$ticketType, $quantity, $unitPrice]) {
        OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => $quantity, 'unit_price' => $unitPrice]);
    }

    return $order;
}

describe('access to the sales page', function () {
    test('lets the owner and admins open it', function () {
        $event = Event::factory()->published()->create();

        $this->actingAs($event->organizer)->get(route('organizer.events.orders', $event))->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get(route('organizer.events.orders', $event))->assertOk();
    });

    test('returns 403 to another organizer and to a customer', function (Closure $makeViewer) {
        $event = Event::factory()->published()->create();

        $this->actingAs($makeViewer->call($this))
            ->get(route('organizer.events.orders', $event))
            ->assertForbidden();
    })->with([
        'another organizer' => fn () => User::factory()->organizer()->create(),
        'customer' => fn () => User::factory()->create(),
    ]);

    test('redirects guests to the login page', function () {
        $event = Event::factory()->published()->create();

        $this->get(route('organizer.events.orders', $event))->assertRedirect(route('login'));
    });

    test('returns 404 for an event that does not exist', function () {
        $this->actingAs(User::factory()->organizer()->create())
            ->get(route('organizer.events.orders', 999999))
            ->assertNotFound();
    });

    test('does not show anyone else\'s sales even when the page is opened by another organizer first', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create();
        orderOf($event, OrderStatus::Paid, [[$ticketType, 1, 3000]], ['user_id' => User::factory()->create(['name' => '秘密の購入者'])->id]);

        $this->actingAs(User::factory()->organizer()->create())
            ->get(route('organizer.events.orders', $event))
            ->assertForbidden()
            ->assertDontSee('秘密の購入者');
    });
});

describe('what the sales page shows', function () {
    test('totals only the paid orders', function () {
        $event = Event::factory()->published()->create();
        $general = TicketType::factory()->for($event)->create(['name' => '一般', 'capacity' => 20]);
        $student = TicketType::factory()->for($event)->create(['name' => '学生', 'capacity' => 10]);

        orderOf($event, OrderStatus::Paid, [[$general, 2, 3000], [$student, 1, 1500]]);
        orderOf($event, OrderStatus::Paid, [[$general, 1, 3000]]);
        orderOf($event, OrderStatus::Pending, [[$general, 5, 3000]]);
        orderOf($event, OrderStatus::Cancelled, [[$general, 5, 3000]]);
        orderOf($event, OrderStatus::Refunded, [[$student, 5, 1500]]);

        $page = $this->actingAs($event->organizer)->get(route('organizer.events.orders', $event));

        $page->assertOk()
            ->assertSeeInOrder(['売上合計', '10,500円'])
            ->assertSeeInOrder(['販売枚数', '4枚'])
            ->assertSeeInOrder(['注文数', '2件']);

        expect(substr_count($page->getContent(), 'data-test="paid-order-row"'))->toBe(2);
    });

    test('shows the sold quantity out of the capacity and the sales for each ticket type, including ones that did not sell', function () {
        $event = Event::factory()->published()->create();
        $general = TicketType::factory()->for($event)->create(['name' => '一般', 'capacity' => 20, 'sales_starts_at' => now()->subDays(2)]);
        TicketType::factory()->for($event)->create(['name' => '学生', 'capacity' => 10, 'sales_starts_at' => now()->subDay()]);
        orderOf($event, OrderStatus::Paid, [[$general, 3, 3000]]);
        orderOf($event, OrderStatus::Pending, [[$general, 4, 3000]]);

        $this->actingAs($event->organizer)
            ->get(route('organizer.events.orders', $event))
            ->assertSeeInOrder(['一般', '3 / 20枚', '9,000円', '学生', '0 / 10枚', '0円']);
    });

    test('lists the paid orders with the buyer, the items, the total, and the detail button from the newest', function () {
        $event = Event::factory()->published()->create();
        $general = TicketType::factory()->for($event)->create(['name' => '一般']);
        $student = TicketType::factory()->for($event)->create(['name' => '学生']);
        $older = orderOf($event, OrderStatus::Paid, [[$general, 2, 3000]], [
            'user_id' => User::factory()->create(['name' => '先に買った人', 'email' => 'older@example.com'])->id,
            'paid_at' => '2030-04-01 09:00:00',
        ]);
        $newer = orderOf($event, OrderStatus::Paid, [[$student, 1, 1500]], [
            'user_id' => User::factory()->create(['name' => '後で買った人', 'email' => 'newer@example.com'])->id,
            'paid_at' => '2030-04-02 09:00:00',
        ]);

        $this->actingAs($event->organizer)
            ->get(route('organizer.events.orders', $event))
            ->assertSeeInOrder(['後で買った人', 'newer@example.com', '学生 × 1枚', '1,500円', '先に買った人', 'older@example.com', '一般 × 2枚', '6,000円'])
            ->assertSee($older->paid_at->isoFormat('M月D日(ddd) HH:mm'))
            ->assertSee(route('orders.show', $older), false)
            ->assertSee(route('orders.show', $newer), false);
    });

    test('does not mix in the orders of other events', function () {
        $event = Event::factory()->published()->create();
        $other = Event::factory()->published()->create();
        orderOf($other, OrderStatus::Paid, [[TicketType::factory()->for($other)->create(), 5, 9999]], [
            'user_id' => User::factory()->create(['name' => '別イベントの購入者'])->id,
        ]);

        $this->actingAs($event->organizer)
            ->get(route('organizer.events.orders', $event))
            ->assertSeeInOrder(['売上合計', '0円'])
            ->assertDontSee('別イベントの購入者')
            ->assertSeeHtml('data-test="no-paid-orders"');
    });

    test('paginates the paid orders ten at a time', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 100]);
        foreach (range(1, 11) as $number) {
            orderOf($event, OrderStatus::Paid, [[$ticketType, 1, 100]], ['paid_at' => now()->subMinutes($number)]);
        }

        $first = $this->actingAs($event->organizer)->get(route('organizer.events.orders', $event));
        $second = $this->actingAs($event->organizer)->get(route('organizer.events.orders', ['event' => $event, 'page' => 2]));

        expect(substr_count($first->getContent(), 'data-test="paid-order-row"'))->toBe(10)
            ->and(substr_count($second->getContent(), 'data-test="paid-order-row"'))->toBe(1);
    });
});

describe('more cases on the sales page', function () {
    test('shows an admin the sales of an event owned by someone else', function () {
        $event = Event::factory()->published()->create(['title' => '他人のイベント']);
        $ticketType = TicketType::factory()->for($event)->create(['name' => '一般']);
        orderOf($event, OrderStatus::Paid, [[$ticketType, 2, 3000]], [
            'user_id' => User::factory()->create(['name' => '購入者の太郎'])->id,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('organizer.events.orders', $event))
            ->assertOk()
            ->assertSee('他人のイベント')
            ->assertSeeInOrder(['売上合計', '6,000円'])
            ->assertSee('購入者の太郎');
    });

    test('counts a free paid order in the order count and the sold quantity without adding to the sales', function () {
        $event = Event::factory()->published()->create();
        $free = TicketType::factory()->for($event)->create(['name' => '招待券', 'price' => 0, 'capacity' => 10]);
        $general = TicketType::factory()->for($event)->create(['name' => '一般', 'sales_starts_at' => now()->addDay()]);
        orderOf($event, OrderStatus::Paid, [[$free, 3, 0]]);
        orderOf($event, OrderStatus::Paid, [[$general, 2, 1000]]);

        $this->actingAs($event->organizer)
            ->get(route('organizer.events.orders', $event))
            ->assertSeeInOrder(['売上合計', '2,000円'])
            ->assertSeeInOrder(['販売枚数', '5枚'])
            ->assertSeeInOrder(['注文数', '2件'])
            ->assertSeeInOrder(['招待券', '3 / 10枚', '0円']);
    });

    test('lists every item of an order that has several ticket types', function () {
        $event = Event::factory()->published()->create();
        $general = TicketType::factory()->for($event)->create(['name' => '一般']);
        $student = TicketType::factory()->for($event)->create(['name' => '学生']);
        orderOf($event, OrderStatus::Paid, [[$general, 2, 3000], [$student, 1, 1500]]);

        $page = $this->actingAs($event->organizer)->get(route('organizer.events.orders', $event));

        $page->assertSee('一般 × 2枚')
            ->assertSee('学生 × 1枚')
            ->assertSee('7,500円');
        expect(substr_count($page->getContent(), 'data-test="paid-order-row"'))->toBe(1);
    });

    test('escapes the name and the email of a buyer', function () {
        $event = Event::factory()->published()->create();
        orderOf($event, OrderStatus::Paid, [[TicketType::factory()->for($event)->create(), 1, 1000]], [
            'user_id' => User::factory()->create(['name' => '<script>alert(1)</script>'])->id,
        ]);

        $this->actingAs($event->organizer)
            ->get(route('organizer.events.orders', $event))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    });

    test('forbids another organizer and a customer from mounting the Livewire component', function (Closure $makeViewer) {
        $event = Event::factory()->published()->create();

        Livewire::actingAs($makeViewer->call($this))
            ->test('pages::organizer.events.orders', ['event' => $event])
            ->assertForbidden();
    })->with([
        'another organizer' => fn () => User::factory()->organizer()->create(),
        'customer' => fn () => User::factory()->create(),
    ]);
});

describe('sales on the organizer menu in more cases', function () {
    test('keeps the sales of each event apart', function () {
        $organizer = User::factory()->organizer()->create();
        $first = Event::factory()->published()->for($organizer, 'organizer')->create(['title' => '最初のイベント', 'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)->addHours(2)]);
        $second = Event::factory()->published()->for($organizer, 'organizer')->create(['title' => '二番目のイベント', 'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHours(2)]);
        orderOf($first, OrderStatus::Paid, [[TicketType::factory()->for($first)->create(), 2, 3000]]);
        orderOf($second, OrderStatus::Paid, [[TicketType::factory()->for($second)->create(), 5, 1000]]);
        orderOf($second, OrderStatus::Refunded, [[TicketType::factory()->for($second)->create(), 9, 1000]]);

        $this->actingAs($organizer)
            ->get(route('organizer.events.index'))
            ->assertSeeInOrder(['最初のイベント', '2枚', '6,000円', '二番目のイベント', '5枚', '5,000円']);
    });

    test('shows the right sales on the second page', function () {
        $organizer = User::factory()->organizer()->create();
        foreach (range(1, 11) as $number) {
            Event::factory()->published()->for($organizer, 'organizer')->create([
                'title' => "イベント{$number}番",
                'starts_at' => now()->addDays($number),
                'ends_at' => now()->addDays($number)->addHours(2),
            ]);
        }
        $last = Event::where('title', 'イベント11番')->sole();
        orderOf($last, OrderStatus::Paid, [[TicketType::factory()->for($last)->create(), 4, 2500]]);

        $this->actingAs($organizer)
            ->get(route('organizer.events.index', ['page' => 2]))
            ->assertOk()
            ->assertSeeInOrder(['イベント11番', '4枚', '10,000円'])
            ->assertDontSee('イベント1番');
    });

    test('returns 403 to an admin because the menu is for organizers', function () {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('organizer.events.index'))
            ->assertForbidden();
    });
});

describe('sales on the organizer menu', function () {
    test('shows the sold quantity and the sales of each event, and not those of other organizers', function () {
        $organizer = User::factory()->organizer()->create();
        $event = Event::factory()->published()->for($organizer, 'organizer')->create(['title' => '春のマルシェ']);
        $ticketType = TicketType::factory()->for($event)->create();
        orderOf($event, OrderStatus::Paid, [[$ticketType, 3, 3000]]);
        orderOf($event, OrderStatus::Pending, [[$ticketType, 5, 3000]]);

        $otherEvent = Event::factory()->published()->create(['title' => '他の主催者のイベント']);
        orderOf($otherEvent, OrderStatus::Paid, [[TicketType::factory()->for($otherEvent)->create(), 7, 1000]]);

        $this->actingAs($organizer)
            ->get(route('organizer.events.index'))
            ->assertOk()
            ->assertSeeInOrder(['春のマルシェ', '3枚', '9,000円'])
            ->assertSee(route('organizer.events.orders', $event), false)
            ->assertDontSee('他の主催者のイベント')
            ->assertDontSee('7,000円');
    });

    test('shows 0 for an event without paid orders', function () {
        $organizer = User::factory()->organizer()->create();
        Event::factory()->for($organizer, 'organizer')->create();

        $this->actingAs($organizer)
            ->get(route('organizer.events.index'))
            ->assertSeeInOrder(['0枚', '0円']);
    });

    test('does not query the sales once for each event', function () {
        $organizer = User::factory()->organizer()->create();
        Event::factory()->count(5)->for($organizer, 'organizer')->create();

        $this->actingAs($organizer);
        DB::enableQueryLog();
        $this->get(route('organizer.events.index'))->assertOk();
        $salesQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from "order_items"') && ! str_contains($query['query'], 'from "events"'));

        expect($salesQueries)->toBeEmpty();
    });
});

describe('Event::soldQuantity() and Event::salesAmount()', function () {
    test('count only the paid orders', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create();
        orderOf($event, OrderStatus::Paid, [[$ticketType, 2, 3000]]);
        orderOf($event, OrderStatus::Paid, [[$ticketType, 1, 0]]);
        orderOf($event, OrderStatus::Pending, [[$ticketType, 4, 3000]]);
        orderOf($event, OrderStatus::Cancelled, [[$ticketType, 4, 3000]]);
        orderOf($event, OrderStatus::Refunded, [[$ticketType, 4, 3000]]);

        expect($event->soldQuantity())->toBe(3)
            ->and($event->salesAmount())->toBe(6000);
    });

    test('are zero for an event without orders', function () {
        $event = Event::factory()->create();

        expect($event->soldQuantity())->toBe(0)
            ->and($event->salesAmount())->toBe(0);
    });

    test('give the same values when they come from withSales()', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create();
        orderOf($event, OrderStatus::Paid, [[$ticketType, 2, 3000]]);
        orderOf($event, OrderStatus::Pending, [[$ticketType, 4, 3000]]);

        $loaded = Event::query()->withSales()->findOrFail($event->id);

        expect($loaded->soldQuantity())->toBe(2)
            ->and($loaded->salesAmount())->toBe(6000)
            ->and($loaded->getAttributes())->toHaveKeys(['sold_quantity', 'sales_amount']);
    });
});

describe('EventPolicy', function () {
    test('lets only the owner and admins view the orders of an event', function () {
        $event = Event::factory()->create();

        expect(Gate::forUser($event->organizer)->allows('viewOrders', $event))->toBeTrue()
            ->and(Gate::forUser(User::factory()->admin()->create())->allows('viewOrders', $event))->toBeTrue()
            ->and(Gate::forUser(User::factory()->organizer()->create())->allows('viewOrders', $event))->toBeFalse()
            ->and(Gate::forUser(User::factory()->create())->allows('viewOrders', $event))->toBeFalse();
    });

    test('lets the owner delete an event only while it has no orders at all', function (?OrderStatus $status, bool $allowed) {
        $event = Event::factory()->create();

        if ($status !== null) {
            orderOf($event, $status, [[TicketType::factory()->for($event)->create(), 1, 1000]]);
        }

        expect(Gate::forUser($event->organizer)->allows('delete', $event))->toBe($allowed);
    })->with([
        'no orders' => [null, true],
        'a pending order' => [OrderStatus::Pending, false],
        'a paid order' => [OrderStatus::Paid, false],
        'a cancelled order' => [OrderStatus::Cancelled, false],
        'a refunded order' => [OrderStatus::Refunded, false],
    ]);

    test('does not let anyone but the owner delete an event, even without orders', function () {
        $event = Event::factory()->create();

        expect(Gate::forUser(User::factory()->admin()->create())->allows('delete', $event))->toBeFalse()
            ->and(Gate::forUser(User::factory()->organizer()->create())->allows('delete', $event))->toBeFalse();
    });
});
