<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\OrderService;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function validTicketTypeInput(array $overrides = []): array
{
    $input = [
        'name' => '一般',
        'price' => 3000,
        'capacity' => 20,
        'sales_starts_at' => '2030-04-01T10:00',
        'sales_ends_at' => '2030-04-30T18:00',
        ...$overrides,
    ];

    return collect($input)->mapWithKeys(fn ($value, $field) => ["ticketTypeForm.$field" => $value])->all();
}

function openEventEditPage(Event $event): Testable
{
    return Livewire::actingAs($event->organizer)
        ->test('pages::organizer.events.edit', ['event' => $event]);
}

describe('ticket type list', function () {
    test('shows each ticket type with its price, capacity, and sales status', function () {
        $event = Event::factory()->create();
        TicketType::factory()->for($event)->create(['name' => '学生', 'price' => 1500, 'capacity' => 30]);

        $this->actingAs($event->organizer)
            ->get(route('organizer.events.edit', $event))
            ->assertOk()
            ->assertSee('学生')
            ->assertSee('1,500円')
            ->assertSee('30枚')
            ->assertSee('販売中');
    });

    test('shows a message when the event has no ticket types', function () {
        $event = Event::factory()->create();

        openEventEditPage($event)->assertSee('券種がありません');
    });

    test('does not list ticket types of other events', function () {
        $event = Event::factory()->create();
        TicketType::factory()->create(['name' => '他のイベントの券種']);

        openEventEditPage($event)->assertDontSee('他のイベントの券種');
    });
});

describe('adding a ticket type', function () {
    test('saves a ticket type of the event with integer price and capacity', function () {
        $event = Event::factory()->create();

        openEventEditPage($event)
            ->set(validTicketTypeInput(['price' => '3000', 'capacity' => '20']))
            ->call('saveTicketType')
            ->assertHasNoErrors()
            ->assertSet('ticketTypeForm.name', '');

        $ticketType = TicketType::sole();

        expect($ticketType)
            ->event_id->toBe($event->id)
            ->name->toBe('一般')
            ->price->toBe(3000)
            ->capacity->toBe(20)
            ->and($ticketType->sales_starts_at->format('Y-m-d H:i'))->toBe('2030-04-01 10:00')
            ->and($ticketType->sales_ends_at->format('Y-m-d H:i'))->toBe('2030-04-30 18:00');
    });

    test('allows a free ticket with a price of 0', function () {
        $event = Event::factory()->create();

        openEventEditPage($event)
            ->set(validTicketTypeInput(['price' => 0]))
            ->call('saveTicketType')
            ->assertHasNoErrors();

        expect(TicketType::sole()->price)->toBe(0);
    });

    test('accepts a name of exactly 50 characters', function () {
        $event = Event::factory()->create();

        openEventEditPage($event)
            ->set(validTicketTypeInput(['name' => str_repeat('あ', 50)]))
            ->call('saveTicketType')
            ->assertHasNoErrors();

        expect(TicketType::count())->toBe(1);
    });

    test('rejects invalid values with the failing rule', function (string $field, int|string $value, string $rule) {
        $event = Event::factory()->create();

        openEventEditPage($event)
            ->set(validTicketTypeInput([$field => $value]))
            ->call('saveTicketType')
            ->assertHasErrors(["ticketTypeForm.$field" => $rule]);

        expect(TicketType::count())->toBe(0);
    })->with([
        'name of 51 characters' => ['name', str_repeat('あ', 51), 'max'],
        'negative price' => ['price', -1, 'min'],
        'decimal price' => ['price', '1.5', 'integer'],
        'price above the limit' => ['price', 10000001, 'max'],
        'capacity of 0' => ['capacity', 0, 'min'],
        'decimal capacity' => ['capacity', '2.5', 'integer'],
        'capacity above the limit' => ['capacity', 100001, 'max'],
    ]);

    test('shows a validation error instead of failing when price or capacity is emptied', function (string $field) {
        $event = Event::factory()->create();

        openEventEditPage($event)
            ->set(validTicketTypeInput([$field => '']))
            ->call('saveTicketType')
            ->assertHasErrors(["ticketTypeForm.$field" => 'required']);

        expect(TicketType::count())->toBe(0);
    })->with(['price', 'capacity']);

    test('rejects a sales end that is not after the sales start', function (string $endsAt) {
        $event = Event::factory()->create();

        openEventEditPage($event)
            ->set(validTicketTypeInput(['sales_ends_at' => $endsAt]))
            ->call('saveTicketType')
            ->assertHasErrors(['ticketTypeForm.sales_ends_at' => 'after']);

        expect(TicketType::count())->toBe(0);
    })->with([
        'before the start' => '2030-03-31T10:00',
        'equal to the start' => '2030-04-01T10:00',
    ]);

    test('rejects an empty form with every field required and shows Japanese field names', function () {
        $event = Event::factory()->create();

        openEventEditPage($event)
            ->call('saveTicketType')
            ->assertHasErrors([
                'ticketTypeForm.name' => 'required',
                'ticketTypeForm.price' => 'required',
                'ticketTypeForm.capacity' => 'required',
                'ticketTypeForm.sales_starts_at' => 'required',
                'ticketTypeForm.sales_ends_at' => 'required',
            ])
            ->assertSee('券種名');

        expect(TicketType::count())->toBe(0);
    });
});

describe('editing a ticket type', function () {
    test('fills the form with the values of the chosen ticket type', function () {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create([
            'name' => '早割',
            'price' => 2500,
            'capacity' => 12,
            'sales_starts_at' => '2030-03-01 09:30:00',
            'sales_ends_at' => '2030-03-20 21:00:00',
        ]);

        openEventEditPage($event)
            ->call('editTicketType', $ticketType->id)
            ->assertSet('editingTicketTypeId', $ticketType->id)
            ->assertSet('ticketTypeForm.name', '早割')
            ->assertSet('ticketTypeForm.price', 2500)
            ->assertSet('ticketTypeForm.capacity', 12)
            ->assertSet('ticketTypeForm.sales_starts_at', '2030-03-01T09:30')
            ->assertSet('ticketTypeForm.sales_ends_at', '2030-03-20T21:00');
    });

    test('updates the chosen ticket type without creating a new one', function () {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000]);
        $untouched = TicketType::factory()->for($event)->create(['name' => '学生', 'price' => 1500]);

        openEventEditPage($event)
            ->call('editTicketType', $ticketType->id)
            ->set('ticketTypeForm.name', '一般（改）')
            ->set('ticketTypeForm.price', '3500')
            ->call('saveTicketType')
            ->assertHasNoErrors()
            ->assertSet('editingTicketTypeId', null)
            ->assertSet('ticketTypeForm.name', '');

        expect(TicketType::count())->toBe(2)
            ->and($ticketType->fresh())->name->toBe('一般（改）')->price->toBe(3500)
            ->and($untouched->fresh())->name->toBe('学生')->price->toBe(1500);
    });

    test('keeps the ticket type unchanged when the update is invalid', function () {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create(['name' => '一般']);

        openEventEditPage($event)
            ->call('editTicketType', $ticketType->id)
            ->set('ticketTypeForm.capacity', 0)
            ->call('saveTicketType')
            ->assertHasErrors(['ticketTypeForm.capacity' => 'min']);

        expect($ticketType->fresh()->capacity)->toBe($ticketType->capacity);
    });

    test('returns the form to adding mode when editing is cancelled', function () {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create();

        openEventEditPage($event)
            ->call('editTicketType', $ticketType->id)
            ->call('cancelTicketTypeEdit')
            ->assertSet('editingTicketTypeId', null)
            ->assertSet('ticketTypeForm.name', '')
            ->assertSet('ticketTypeForm.price', '');
    });
});

describe('deleting a ticket type', function () {
    test('deletes a ticket type of a draft event', function () {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create();

        openEventEditPage($event)->call('deleteTicketType', $ticketType->id);

        expect(TicketType::count())->toBe(0);
    });

    test('deletes one of several ticket types of a published event', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create();
        TicketType::factory()->for($event)->create();

        openEventEditPage($event)->call('deleteTicketType', $ticketType->id);

        expect(TicketType::whereKey($ticketType->id)->exists())->toBeFalse()
            ->and(TicketType::count())->toBe(1);
    });

    test('keeps the last ticket type of a published event', function () {
        $event = Event::factory()->published()->create();
        $ticketType = TicketType::factory()->for($event)->create();

        openEventEditPage($event)
            ->call('deleteTicketType', $ticketType->id)
            ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains(json_encode($params, JSON_UNESCAPED_UNICODE), '最後の券種は削除できません'));

        expect(TicketType::whereKey($ticketType->id)->exists())->toBeTrue();
    });

    test('resets the form when the ticket type being edited is deleted', function () {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create();

        openEventEditPage($event)
            ->call('editTicketType', $ticketType->id)
            ->call('deleteTicketType', $ticketType->id)
            ->assertSet('editingTicketTypeId', null)
            ->assertSet('ticketTypeForm.name', '');
    });

    test('asks for confirmation before deleting', function () {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create();

        openEventEditPage($event)
            ->assertSeeHtml('wire:confirm="この券種を削除しますか？"');
    });
});

describe('publishing and ticket types', function () {
    test('does not publish an event without ticket types', function () {
        $event = Event::factory()->create();

        openEventEditPage($event)
            ->call('togglePublish')
            ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains(json_encode($params, JSON_UNESCAPED_UNICODE), '券種が1つもないため公開できません'));

        expect($event->fresh()->status)->toBe(EventStatus::Draft);
    });

    test('publishes an event that has a ticket type', function () {
        $event = Event::factory()->has(TicketType::factory())->create();

        openEventEditPage($event)->call('togglePublish');

        expect($event->fresh()->status)->toBe(EventStatus::Published);
    });

    test('returns a published event to draft even if it has no ticket types', function () {
        $event = Event::factory()->published()->create();

        openEventEditPage($event)->call('togglePublish');

        expect($event->fresh()->status)->toBe(EventStatus::Draft);
    });
});

describe('authorization inside the ticket type methods', function () {
    test('forbids another organizer after the page was opened', function (string $method, bool $needsTicketTypeId) {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create(['name' => '元の券種名']);

        $component = openEventEditPage($event)
            ->set(validTicketTypeInput(['name' => '乗っ取られた券種名']));

        $this->actingAs(User::factory()->organizer()->create());

        $component->call($method, ...($needsTicketTypeId ? [$ticketType->id] : []))->assertForbidden();

        expect(TicketType::count())->toBe(1)
            ->and($ticketType->fresh()->name)->toBe('元の券種名');
    })->with([
        'editTicketType' => ['editTicketType', true],
        'saveTicketType' => ['saveTicketType', false],
        'deleteTicketType' => ['deleteTicketType', true],
    ]);

    test('cannot edit or delete a ticket type of another event', function (string $method) {
        $event = Event::factory()->create();
        $foreign = TicketType::factory()->create(['name' => '他のイベントの券種']);

        openEventEditPage($event)->call($method, $foreign->id)->assertNotFound();

        expect($foreign->fresh()->name)->toBe('他のイベントの券種');
    })->with(['editTicketType', 'deleteTicketType']);

    test('cannot update a ticket type of another event by tampering with the editing id', function () {
        $event = Event::factory()->create();
        $foreign = TicketType::factory()->create(['name' => '他のイベントの券種']);

        openEventEditPage($event)
            ->set('editingTicketTypeId', $foreign->id)
            ->set(validTicketTypeInput(['name' => '書き換え']))
            ->call('saveTicketType')
            ->assertNotFound();

        expect($foreign->fresh()->name)->toBe('他のイベントの券種');
    });
});

describe('ticket type access by other users', function () {
    test('returns 403 and adds nothing when another organizer opens the edit page directly', function () {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create(['name' => '元の券種名']);
        $otherOrganizer = User::factory()->organizer()->create();

        $this->actingAs($otherOrganizer)
            ->get(route('organizer.events.edit', $event))
            ->assertForbidden();

        Livewire::actingAs($otherOrganizer)
            ->test('pages::organizer.events.edit', ['event' => $event])
            ->assertForbidden();

        expect(TicketType::count())->toBe(1)
            ->and($ticketType->fresh()->name)->toBe('元の券種名');
    });

    test('forbids a user who is no longer an organizer from adding a ticket type', function () {
        $event = Event::factory()->create();

        $component = openEventEditPage($event)->set(validTicketTypeInput());

        $this->actingAs(User::factory()->create());

        $component->call('saveTicketType')->assertForbidden();

        expect(TicketType::count())->toBe(0);
    });
});

describe('ticket type price boundary', function () {
    test('rejects a negative price with a Japanese message and keeps the existing price', function () {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create(['price' => 3000]);

        openEventEditPage($event)
            ->call('editTicketType', $ticketType->id)
            ->set('ticketTypeForm.price', -1)
            ->call('saveTicketType')
            ->assertHasErrors(['ticketTypeForm.price' => 'min'])
            ->assertSee('価格');

        expect($ticketType->fresh()->price)->toBe(3000)
            ->and(TicketType::count())->toBe(1);
    });

    test('allows updating a price to 0', function () {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create(['price' => 3000]);

        openEventEditPage($event)
            ->call('editTicketType', $ticketType->id)
            ->set('ticketTypeForm.price', 0)
            ->call('saveTicketType')
            ->assertHasNoErrors();

        expect($ticketType->fresh()->price)->toBe(0);
    });
});

describe('publishing after adding a ticket type', function () {
    test('refuses to publish, then publishes once a ticket type is added', function () {
        $event = Event::factory()->create();

        $component = openEventEditPage($event)->call('togglePublish');

        expect($event->fresh()->status)->toBe(EventStatus::Draft);

        $component
            ->set(validTicketTypeInput())
            ->call('saveTicketType')
            ->call('togglePublish')
            ->assertDispatched('toast-show', fn (string $name, array $params) => str_contains(json_encode($params, JSON_UNESCAPED_UNICODE), 'イベントを公開しました'));

        expect($event->fresh()->status)->toBe(EventStatus::Published);
    });
});

/**
 * 券種の表の「削除」ボタンが無効かどうかを、表の順（販売開始順、同じなら ID 順）に返す。
 *
 * @return array<int, bool>
 */
function deleteTicketTypeButtonsDisabled(Testable $page): array
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
    libxml_clear_errors();

    $disabled = [];

    foreach ((new DOMXPath($dom))->query("//button[@data-test='delete-ticket-type-button']") as $button) {
        $disabled[] = $button->hasAttribute('disabled');
    }

    return $disabled;
}

describe('ticket types that have orders', function () {
    test('keeps a ticket type that has orders and tells the organizer instead of failing', function (OrderStatus $status) {
        $event = Event::factory()->create();
        $ordered = TicketType::factory()->for($event)->create();
        $item = OrderItem::factory()->for($ordered)->for(Order::factory()->for($event)->create(['status' => $status]), 'order')->create();

        openEventEditPage($event)
            ->call('deleteTicketType', $ordered->id)
            ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains(json_encode($params, JSON_UNESCAPED_UNICODE), '注文のある券種は削除できません'));

        expect(TicketType::whereKey($ordered->id)->exists())->toBeTrue()
            ->and(OrderItem::whereKey($item->id)->exists())->toBeTrue();
    })->with([
        'pending' => OrderStatus::Pending,
        'paid' => OrderStatus::Paid,
        'cancelled' => OrderStatus::Cancelled,
        'refunded' => OrderStatus::Refunded,
    ]);

    test('still deletes a ticket type without orders in the same event', function () {
        $event = Event::factory()->create();
        TicketType::factory()->for($event)->create();
        $free = TicketType::factory()->for($event)->create();

        openEventEditPage($event)->call('deleteTicketType', $free->id);

        expect(TicketType::whereKey($free->id)->exists())->toBeFalse()
            ->and(TicketType::count())->toBe(1);
    });

    test('disables only the delete button of ticket types that have orders and explains why', function () {
        $event = Event::factory()->create();
        $ordered = TicketType::factory()->for($event)->create();
        TicketType::factory()->for($event)->create();
        OrderItem::factory()->for($ordered)->for(Order::factory()->for($event), 'order')->create();

        $page = openEventEditPage($event);

        expect(deleteTicketTypeButtonsDisabled($page))->toBe([true, false]);

        $page->assertSee('注文のある券種は削除できません。');
    });

    test('does not show the explanation when no ticket type has orders', function () {
        $event = Event::factory()->create();
        TicketType::factory()->for($event)->create();

        $page = openEventEditPage($event);

        expect(deleteTicketTypeButtonsDisabled($page))->toBe([false]);

        $page->assertDontSee('注文のある券種は削除できません。');
    });
});

describe('lowering the capacity of a ticket type that already sold', function () {
    function ticketTypeWithOrder(OrderStatus $status, int $quantity, array $orderAttributes = [], int $capacity = 10): TicketType
    {
        $event = Event::factory()->create();
        $ticketType = TicketType::factory()->for($event)->create(['capacity' => $capacity]);
        $order = Order::factory()->for($event)->create($orderAttributes);
        $order->forceFill(['status' => $status])->save();
        OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => $quantity]);

        return $ticketType;
    }

    function editCapacityTo(TicketType $ticketType, int $capacity): Testable
    {
        return openEventEditPage($ticketType->event)
            ->call('editTicketType', $ticketType->id)
            ->set('ticketTypeForm.capacity', (string) $capacity)
            ->call('saveTicketType');
    }

    test('rejects a capacity smaller than the paid quantity and keeps the capacity', function () {
        $ticketType = ticketTypeWithOrder(OrderStatus::Paid, 6);

        editCapacityTo($ticketType, 5)
            ->assertHasErrors(['ticketTypeForm.capacity' => 'min'])
            ->assertSee('定員は、販売済み・確保中の枚数（6枚）より少なくできません。');

        expect($ticketType->fresh()->capacity)->toBe(10);
    });

    test('accepts a capacity equal to the sold quantity', function () {
        $ticketType = ticketTypeWithOrder(OrderStatus::Paid, 6);

        editCapacityTo($ticketType, 6)->assertHasNoErrors();

        expect($ticketType->fresh()->capacity)->toBe(6);
    });

    test('counts a recent pending order because it holds tickets', function () {
        $ticketType = ticketTypeWithOrder(OrderStatus::Pending, 4);

        editCapacityTo($ticketType, 3)->assertHasErrors(['ticketTypeForm.capacity' => 'min']);

        expect($ticketType->fresh()->capacity)->toBe(10);
    });

    test('does not count a pending order that no longer holds tickets or a cancelled order', function (OrderStatus $status, array $orderAttributes) {
        $ticketType = ticketTypeWithOrder($status, 4, $orderAttributes);

        editCapacityTo($ticketType, 1)->assertHasNoErrors();

        expect($ticketType->fresh()->capacity)->toBe(1);
    })->with([
        'an old pending order' => [OrderStatus::Pending, ['created_at' => now()->subMinutes(OrderService::PENDING_MINUTES + 1)]],
        'a cancelled order' => [OrderStatus::Cancelled, []],
        'a refunded order' => [OrderStatus::Refunded, []],
    ]);

    test('does not count the orders of another ticket type', function () {
        $ticketType = ticketTypeWithOrder(OrderStatus::Paid, 6);
        $other = TicketType::factory()->for($ticketType->event)->create(['capacity' => 10]);

        editCapacityTo($other, 1)->assertHasNoErrors();

        expect($other->fresh()->capacity)->toBe(1);
    });

    test('lets the organizer change other fields of a sold-out ticket type without touching the capacity', function () {
        $ticketType = ticketTypeWithOrder(OrderStatus::Paid, 10);

        openEventEditPage($ticketType->event)
            ->call('editTicketType', $ticketType->id)
            ->set('ticketTypeForm.name', '一般（改）')
            ->call('saveTicketType')
            ->assertHasNoErrors();

        expect($ticketType->fresh())->name->toBe('一般（改）')->capacity->toBe(10);
    });

    test('does not limit the capacity of a new ticket type', function () {
        $event = Event::factory()->create();

        openEventEditPage($event)
            ->set(validTicketTypeInput(['capacity' => 1]))
            ->call('saveTicketType')
            ->assertHasNoErrors();

        expect($event->ticketTypes()->sole()->capacity)->toBe(1);
    });

    test('still requires the capacity to be at least 1', function () {
        $ticketType = ticketTypeWithOrder(OrderStatus::Cancelled, 4);

        editCapacityTo($ticketType, 0)->assertHasErrors(['ticketTypeForm.capacity' => 'min']);
    });
});

describe('capacity limit when orders arrive during editing', function () {
    test('judges the lower limit when saving, not when the edit started', function () {
        $ticketType = ticketTypeWithOrder(OrderStatus::Cancelled, 1);

        $component = openEventEditPage($ticketType->event)
            ->call('editTicketType', $ticketType->id)
            ->set('ticketTypeForm.capacity', '3');

        $order = Order::factory()->for($ticketType->event)->create();
        $order->forceFill(['status' => OrderStatus::Paid])->save();
        OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => 7]);

        $component->call('saveTicketType')
            ->assertHasErrors(['ticketTypeForm.capacity' => 'min'])
            ->assertSee('定員は、販売済み・確保中の枚数（7枚）より少なくできません。');

        expect($ticketType->fresh()->capacity)->toBe(10);
    });
});

describe('deleting a ticket type that sold', function () {
    test('keeps a ticket type with a paid order and tells the organizer', function () {
        $ticketType = ticketTypeWithOrder(OrderStatus::Paid, 2);

        openEventEditPage($ticketType->event)
            ->call('deleteTicketType', $ticketType->id)
            ->assertDispatched('toast-show', fn (string $name, array $params) => str_contains(json_encode($params, JSON_UNESCAPED_UNICODE), '注文のある券種は削除できません'));

        expect(TicketType::whereKey($ticketType->id)->exists())->toBeTrue();
    });
});
