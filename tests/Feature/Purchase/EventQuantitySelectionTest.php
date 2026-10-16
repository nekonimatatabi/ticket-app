<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function openEventPage(Event $event): Testable
{
    return Livewire::test('pages::events.show', ['event' => $event]);
}

/**
 * 券種の枚数セレクトの選択肢と、無効かどうかを、描画された HTML から取り出す。
 *
 * @return array{options: array<int, int>, disabled: bool}
 */
function quantitySelectOf(Testable $page, TicketType $ticketType): array
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $select = $xpath->query("//select[@data-test='quantity-select-{$ticketType->id}']")->item(0);

    expect($select)->not->toBeNull();

    $options = [];

    foreach ($xpath->query('.//option', $select) as $option) {
        $options[] = (int) $option->getAttribute('value');
    }

    return ['options' => $options, 'disabled' => $select->hasAttribute('disabled')];
}

function purchaseButtonIsDisabled(Testable $page): bool
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
    libxml_clear_errors();

    $button = (new DOMXPath($dom))->query("//button[@data-test='continue-to-purchase-button']")->item(0);

    expect($button)->not->toBeNull();

    return $button->hasAttribute('disabled');
}

describe('quantity selects', function () {
    test('offers 0 up to the smaller of the maximum and the remaining tickets', function (int $capacity, int $paid, array $expected) {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => $capacity]);

        if ($paid > 0) {
            OrderItem::factory()->for($ticketType)->for(Order::factory()->paid()->for($event), 'order')->create(['quantity' => $paid]);
        }

        $select = quantitySelectOf(openEventPage($event), $ticketType);

        expect($select['options'])->toBe($expected)
            ->and($select['disabled'])->toBeFalse();
    })->with([
        'few tickets remain' => [3, 0, [0, 1, 2, 3]],
        'many tickets remain' => [50, 0, range(0, 10)],
        'paid orders reduce the choices' => [12, 4, range(0, 8)],
        'exactly the maximum remains' => [10, 0, range(0, 10)],
    ]);

    test('disables the select of a ticket type that is not on sale', function (string $startsAt, string $endsAt) {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create([
            'sales_starts_at' => now()->modify($startsAt),
            'sales_ends_at' => now()->modify($endsAt),
        ]);

        $select = quantitySelectOf(openEventPage($event), $ticketType);

        expect($select['disabled'])->toBeTrue()
            ->and($select['options'])->toBe([0]);
    })->with([
        'before the sales start' => ['+1 day', '+2 days'],
        'after the sales end' => ['-3 days', '-2 days'],
    ]);

    test('disables the select of a sold out ticket type', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => 5]);
        OrderItem::factory()->for($ticketType)->for(Order::factory()->paid()->for($event), 'order')->create(['quantity' => 5]);

        $select = quantitySelectOf(openEventPage($event), $ticketType);

        expect($select['disabled'])->toBeTrue()
            ->and($select['options'])->toBe([0]);
    });

    test('keeps the other ticket types selectable when one is sold out', function () {
        $event = Event::factory()->published()->create();
        $soldOut = TicketType::factory()->for($event)->create(['capacity' => 1]);
        $available = TicketType::factory()->for($event)->create(['capacity' => 4]);
        OrderItem::factory()->for($soldOut)->for(Order::factory()->paid()->for($event), 'order')->create(['quantity' => 1]);

        $page = openEventPage($event);

        expect(quantitySelectOf($page, $soldOut)['disabled'])->toBeTrue()
            ->and(quantitySelectOf($page, $available)['disabled'])->toBeFalse()
            ->and(purchaseButtonIsDisabled($page))->toBeFalse();
    });

    test('disables the purchase button when no ticket type can be bought', function () {
        $event = Event::factory()->published()->create();
        TicketType::factory()->for($event)->create(['sales_starts_at' => now()->addDay(), 'sales_ends_at' => now()->addDays(2)]);

        expect(purchaseButtonIsDisabled(openEventPage($event)))->toBeTrue();
    });
});

describe('continuing to the purchase page', function () {
    test('saves the chosen quantities in the session and goes to the purchase page', function () {
        $event = Event::factory()->published()->create();
        $general = TicketType::factory()->for($event)->create();
        $student = TicketType::factory()->for($event)->create();

        openEventPage($event)
            ->set("quantities.{$general->id}", '2')
            ->set("quantities.{$student->id}", '1')
            ->call('continueToPurchase')
            ->assertHasNoErrors()
            ->assertRedirect(route('events.purchase', $event));

        expect(session("purchase.{$event->id}"))->toBe([$general->id => 2, $student->id => 1]);
    });

    test('leaves out ticket types with 0 tickets', function () {
        $event = Event::factory()->published()->create();
        $chosen = TicketType::factory()->for($event)->create();
        $skipped = TicketType::factory()->for($event)->create();

        openEventPage($event)
            ->set("quantities.{$chosen->id}", 3)
            ->set("quantities.{$skipped->id}", 0)
            ->call('continueToPurchase');

        expect(session("purchase.{$event->id}"))->toBe([$chosen->id => 3]);
    });

    test('drops ticket types of other events and values that are not numbers', function () {
        $event = Event::factory()->published()->create();
        $chosen = TicketType::factory()->for($event)->create();
        $other = TicketType::factory()->for($event)->create();
        $foreign = TicketType::factory()->for(Event::factory()->published())->create();

        openEventPage($event)
            ->set("quantities.{$chosen->id}", 2)
            ->set("quantities.{$other->id}", 'abc')
            ->set("quantities.{$foreign->id}", 5)
            ->call('continueToPurchase');

        expect(session("purchase.{$event->id}"))->toBe([$chosen->id => 2]);
    });

    test('asks to choose a ticket when nothing is selected and saves nothing', function () {
        $event = Event::factory()->published()->create();
        TicketType::factory()->for($event)->create();

        openEventPage($event)
            ->call('continueToPurchase')
            ->assertHasErrors('quantities')
            ->assertSee('チケットを1枚以上選んでください。')
            ->assertNoRedirect();

        expect(session()->has("purchase.{$event->id}"))->toBeFalse();
    });

    test('lets a guest choose quantities', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create();

        openEventPage($event)
            ->set("quantities.{$ticketType->id}", 1)
            ->call('continueToPurchase')
            ->assertRedirect(route('events.purchase', $event));

        expect(auth()->check())->toBeFalse();
    });

    test('checks the permission to view the event again when continuing', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create();

        $page = openEventPage($event)->set("quantities.{$ticketType->id}", 1);
        $event->status = EventStatus::Draft;
        $event->save();

        $page->call('continueToPurchase')->assertNotFound();

        expect(session()->has("purchase.{$event->id}"))->toBeFalse();
    });
});

describe('choosing 0 tickets', function () {
    test('asks to choose a ticket and saves nothing when every quantity is 0', function () {
        $event = Event::factory()->published()->create();
        $general = TicketType::factory()->for($event)->create();
        $student = TicketType::factory()->for($event)->create();

        openEventPage($event)
            ->set("quantities.{$general->id}", 0)
            ->set("quantities.{$student->id}", 0)
            ->call('continueToPurchase')
            ->assertHasErrors('quantities')
            ->assertSee('チケットを1枚以上選んでください。')
            ->assertNoRedirect();

        expect(session()->has("purchase.{$event->id}"))->toBeFalse();
    });

    test('asks to choose a ticket and saves nothing when the quantities are negative', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create();

        openEventPage($event)
            ->set("quantities.{$ticketType->id}", -1)
            ->call('continueToPurchase')
            ->assertSee('チケットを1枚以上選んでください。')
            ->assertNoRedirect();

        expect(session()->has("purchase.{$event->id}"))->toBeFalse();
    });
});
