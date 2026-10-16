<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->mock(CheckoutService::class)->allows('createSession')->andReturn('https://checkout.stripe.com/c/pay/cs_test_dummy');
});

function chooseTickets(Event $event, array $quantities): void
{
    session()->put("purchase.{$event->id}", $quantities);
}

function openPurchasePage(User $user, Event $event): Testable
{
    return Livewire::actingAs($user)->test('pages::events.purchase', ['event' => $event]);
}

describe('access', function () {
    test('redirects guests to the login page', function () {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => 1]);

        $this->get(route('events.purchase', $event))->assertRedirect(route('login'));
    });

    test('redirects users with an unverified email address to the verification notice', function () {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => 1]);

        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('events.purchase', $event))
            ->assertRedirect(route('verification.notice'));
    });

    test('goes back to the event page when nothing was chosen', function () {
        $event = Event::factory()->published()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('events.purchase', $event))
            ->assertRedirect(route('events.show', $event));
    });

    test('returns 404 for an event that is not published when the user is not its owner', function () {
        $event = Event::factory()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => 1]);

        $this->actingAs(User::factory()->create())
            ->get(route('events.purchase', $event))
            ->assertNotFound();
    });
});

describe('the table', function () {
    test('shows each chosen ticket type with its price, quantity, subtotal, and the total', function () {
        $event = Event::factory()->published()->create(['title' => '春のマルシェ']);
        $general = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000]);
        $student = TicketType::factory()->for($event)->create(['name' => '学生', 'price' => 1500]);
        TicketType::factory()->for($event)->create(['name' => '選ばなかった券']);
        chooseTickets($event, [$general->id => 2, $student->id => 3]);

        $this->actingAs(User::factory()->create())
            ->get(route('events.purchase', $event))
            ->assertOk()
            ->assertSee('春のマルシェ')
            ->assertSee('一般')
            ->assertSee('3,000円')
            ->assertSee('2枚')
            ->assertSee('6,000円')
            ->assertSee('学生')
            ->assertSee('1,500円')
            ->assertSee('3枚')
            ->assertSee('4,500円')
            ->assertSee('10,500円')
            ->assertDontSee('選ばなかった券');
    });

    test('shows a free ticket as 0 yen', function () {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create(['price' => 0])->id => 2]);

        $this->actingAs(User::factory()->create())
            ->get(route('events.purchase', $event))
            ->assertOk()
            ->assertSee('0円');
    });
});

describe('proceeding to payment', function () {
    test('creates a pending order with items and goes to the Stripe checkout page', function () {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $general = TicketType::factory()->for($event)->create(['price' => 3000]);
        $student = TicketType::factory()->for($event)->create(['price' => 1500]);
        chooseTickets($event, [$general->id => 2, $student->id => 1]);

        openPurchasePage($user, $event)
            ->call('proceedToPayment')
            ->assertHasNoErrors()
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_dummy');

        $order = Order::sole();

        expect($order)
            ->user_id->toBe($user->id)
            ->event_id->toBe($event->id)
            ->total_amount->toBe(7500)
            ->and($order->items->pluck('unit_price', 'ticket_type_id')->all())->toEqual([$general->id => 3000, $student->id => 1500])
            ->and($order->items->pluck('quantity', 'ticket_type_id')->all())->toEqual([$general->id => 2, $student->id => 1]);
    });

    test('clears the chosen quantities and does not create a second order when pressed again', function () {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => 1]);

        $page = openPurchasePage(User::factory()->create(), $event);
        $page->call('proceedToPayment');
        $page->call('proceedToPayment')->assertRedirect(route('events.show', $event));

        expect(Order::count())->toBe(1)
            ->and(session()->has("purchase.{$event->id}"))->toBeFalse();
    });

    test('lets the owner of an event buy tickets for it', function () {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => 1]);

        openPurchasePage($event->organizer, $event)->call('proceedToPayment');

        expect(Order::sole()->user_id)->toBe($event->organizer_id);
    });
});

describe('purchase errors', function () {
    test('shows the message and creates no order when the tickets sold out after they were chosen', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'capacity' => 2]);
        chooseTickets($event, [$ticketType->id => 2]);
        $page = openPurchasePage(User::factory()->create(), $event);
        OrderItem::factory()->for($ticketType)->for(Order::factory()->paid()->for($event), 'order')->create(['quantity' => 1]);
        $ordersBefore = Order::count();

        $page->call('proceedToPayment')
            ->assertSee('「一般」は残り枚数が足りません（残り1枚）。')
            ->assertSeeHtml('data-test="purchase-error"')
            ->assertNoRedirect();

        expect(Order::count())->toBe($ordersBefore)
            ->and(session()->has("purchase.{$event->id}"))->toBeTrue();
    });

    test('shows the message when the sales period has ended', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['name' => '早割', 'sales_starts_at' => now()->subDays(3), 'sales_ends_at' => now()->subDay()]);
        chooseTickets($event, [$ticketType->id => 1]);

        openPurchasePage(User::factory()->create(), $event)
            ->call('proceedToPayment')
            ->assertSee('「早割」は販売期間外です。');

        expect(Order::count())->toBe(0);
    });

    test('shows the message when the quantity is above the maximum', function () {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create(['capacity' => 50])->id => 11]);

        openPurchasePage(User::factory()->create(), $event)
            ->call('proceedToPayment')
            ->assertSee('枚数は1〜10枚で指定してください。');

        expect(Order::count())->toBe(0);
    });

    test('shows the message when a ticket type belongs to another event', function () {
        $event = Event::factory()->published()->create();
        $foreign = TicketType::factory()->for(Event::factory()->published())->create();
        chooseTickets($event, [$foreign->id => 1]);

        openPurchasePage(User::factory()->create(), $event)
            ->call('proceedToPayment')
            ->assertSee('選択された券種が見つかりません。');

        expect(Order::count())->toBe(0);
    });

    test('shows the message to the owner when the event stopped being published', function () {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => 1]);
        $page = openPurchasePage($event->organizer, $event);
        $event->status = EventStatus::Unpublished;
        $event->save();

        $page->call('proceedToPayment')->assertSee('このイベントは現在購入できません。');

        expect(Order::count())->toBe(0);
    });

    test('returns 404 to other users when the event stopped being published', function () {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => 1]);
        $page = openPurchasePage(User::factory()->create(), $event);
        $event->status = EventStatus::Unpublished;
        $event->save();

        $page->call('proceedToPayment')->assertNotFound();

        expect(Order::count())->toBe(0);
    });

    test('offers a link back to choose the tickets again', function () {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => 11]);

        openPurchasePage(User::factory()->create(), $event)
            ->call('proceedToPayment')
            ->assertSee('券種を選び直す')
            ->assertSee(route('events.show', $event), false);
    });
});

describe('quantity of 0 and invalid quantities', function () {
    test('shows the message and creates no order when every chosen quantity is 0', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create();
        chooseTickets($event, [$ticketType->id => 0]);

        openPurchasePage(User::factory()->create(), $event)
            ->call('proceedToPayment')
            ->assertSee('チケットを1枚以上選んでください。')
            ->assertSeeHtml('data-test="purchase-error"')
            ->assertNoRedirect();

        expect(Order::count())->toBe(0)
            ->and(OrderItem::count())->toBe(0);
    });

    test('ignores a ticket type with 0 and orders only the ones with a positive quantity', function () {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        $chosen = TicketType::factory()->for($event)->create(['price' => 1000]);
        $skipped = TicketType::factory()->for($event)->create(['price' => 5000]);
        chooseTickets($event, [$chosen->id => 2, $skipped->id => 0]);

        openPurchasePage($user, $event)
            ->call('proceedToPayment')
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_dummy');

        expect(Order::sole()->total_amount)->toBe(2000)
            ->and(OrderItem::sole())
            ->ticket_type_id->toBe($chosen->id)
            ->quantity->toBe(2);
    });

    test('shows the message and creates no order when the quantity is out of range', function (int $quantity) {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create(['capacity' => 50])->id => $quantity]);

        openPurchasePage(User::factory()->create(), $event)
            ->call('proceedToPayment')
            ->assertSee('枚数は1〜10枚で指定してください。')
            ->assertSeeHtml('data-test="purchase-error"')
            ->assertNoRedirect();

        expect(Order::count())->toBe(0);
    })->with([
        'negative' => -1,
        'above the maximum' => 11,
    ]);

    test('goes back to the event page and creates no order when the quantity is not a whole number', function (mixed $quantity) {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => $quantity]);

        $this->actingAs(User::factory()->create())
            ->get(route('events.purchase', $event))
            ->assertRedirect(route('events.show', $event));

        $ticketType = TicketType::factory()->for($event)->create();
        chooseTickets($event, [$ticketType->id => 1]);
        $page = openPurchasePage(User::factory()->create(), $event);
        chooseTickets($event, [$ticketType->id => $quantity]);

        $page->call('proceedToPayment')->assertRedirect(route('events.show', $event));

        expect(Order::count())->toBe(0);
    })->with([
        'decimal' => 1.5,
        'letters' => 'abc',
        'numeric string' => '2',
        'null' => null,
    ]);
});

describe('guests', function () {
    test('returns 403 and creates no order when a guest presses the payment button', function () {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => 1]);

        Livewire::test('pages::events.purchase', ['event' => $event])
            ->call('proceedToPayment')
            ->assertForbidden();

        expect(Order::count())->toBe(0);
    });

    test('returns 403 and creates no order when the user logged out after opening the page', function () {
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => 1]);
        $page = openPurchasePage(User::factory()->create(), $event);

        auth()->logout();

        $page->call('proceedToPayment')->assertForbidden();

        expect(Order::count())->toBe(0);
    });

    test('sends a guest back to the purchase page after logging in', function () {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create();
        chooseTickets($event, [TicketType::factory()->for($event)->create()->id => 1]);

        $this->get(route('events.purchase', $event))->assertRedirect(route('login'));

        expect(session('url.intended'))->toBe(route('events.purchase', $event));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('events.purchase', $event));
    });
});
