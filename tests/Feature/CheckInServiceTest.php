<?php

use App\Enums\CheckInStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckInService;
use Illuminate\Support\Facades\Log;

describe('checking a ticket in', function () {
    test('marks the ticket as checked in and records when and by whom', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event, buyer: User::factory()->create(['name' => '山田 花子']));
        $this->freezeTime();

        $result = app(CheckInService::class)->checkIn($event, $ticket->code, $event->organizer);

        expect($result->status)->toBe(CheckInStatus::Success)
            ->and($result->ticket->orderItem->order->user->name)->toBe('山田 花子')
            ->and($ticket->fresh())
            ->checked_in_by->toBe($event->organizer->id)
            ->and($ticket->fresh()->checked_in_at->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));
    });

    test('tells that the ticket was already checked in, with the first time and person, and changes nothing', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        $first = User::factory()->organizer()->create(['name' => '最初の担当者']);
        app(CheckInService::class)->checkIn($event, $ticket->code, $first);
        $firstTime = $ticket->fresh()->checked_in_at;

        $this->travel(10)->minutes();
        $result = app(CheckInService::class)->checkIn($event, $ticket->code, $event->organizer);

        expect($result->status)->toBe(CheckInStatus::AlreadyCheckedIn)
            ->and($result->ticket->checkedInBy->name)->toBe('最初の担当者')
            ->and($ticket->fresh())
            ->checked_in_by->toBe($first->id)
            ->and($ticket->fresh()->checked_in_at->format('Y-m-d H:i:s'))->toBe($firstTime->format('Y-m-d H:i:s'));
    });

    test('lets only the first of two devices win when both read the same ticket', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        $other = User::factory()->organizer()->create();

        // 別の端末が先に受付した状況: 「受付済みでない行だけ更新する」UPDATE が 0 件になり、成功にならない
        Ticket::query()->whereKey($ticket->id)->update(['checked_in_at' => now(), 'checked_in_by' => $other->id]);

        $result = app(CheckInService::class)->checkIn($event, $ticket->code, $event->organizer);

        expect($result->status)->toBe(CheckInStatus::AlreadyCheckedIn)
            ->and($ticket->fresh()->checked_in_by)->toBe($other->id);
    });

    test('accepts the code in lowercase and with spaces around it', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);

        $result = app(CheckInService::class)->checkIn($event, '  '.strtolower($ticket->code)."\n", $event->organizer);

        expect($result->status)->toBe(CheckInStatus::Success);
    });

    test('says not found for text that is not a ticket code, without searching the database', function (string $code) {
        $event = Event::factory()->published()->create();
        issuedTicket($event);
        $staff = $event->organizer;
        Log::spy();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $result = app(CheckInService::class)->checkIn($event, $code, $staff);

        expect($result->status)->toBe(CheckInStatus::NotFound)
            ->and($result->ticket)->toBeNull()
            ->and($queries)->toBe(0);
    })->with([
        'empty' => '',
        'too short' => '01HZX3K9F2',
        'too long' => '01HZX3K9F2M7N8P4Q5R6S7T8V1X',
        'letters that ULID does not use' => 'ILOU0000000000000000000000',
        'symbols and sql' => "01HZX3K9F2M7N8P4Q5R6S7T8';--",
        'a url' => 'https://example.com/tickets/1',
    ]);

    test('says not found for a well-formed code that nobody has', function () {
        $event = Event::factory()->published()->create();

        $result = app(CheckInService::class)->checkIn($event, '01HZX3K9F2M7N8P4Q5R6S7T8V1', $event->organizer);

        expect($result->status)->toBe(CheckInStatus::NotFound);
    });

    test('rejects a ticket of another event without checking it in or showing it', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket(Event::factory()->published()->create());

        $result = app(CheckInService::class)->checkIn($event, $ticket->code, $event->organizer);

        expect($result->status)->toBe(CheckInStatus::WrongEvent)
            ->and($result->ticket)->toBeNull()
            ->and($ticket->fresh()->checked_in_at)->toBeNull();
    });

    test('rejects a ticket whose order is not paid', function (OrderStatus $status) {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event, $status);

        $result = app(CheckInService::class)->checkIn($event, $ticket->code, $event->organizer);

        expect($result->status)->toBe(CheckInStatus::NotPaid)
            ->and($ticket->fresh()->checked_in_at)->toBeNull();
    })->with([OrderStatus::Pending, OrderStatus::Cancelled, OrderStatus::Refunded]);
});

describe('undoing a check-in', function () {
    test('puts the ticket back to not checked in', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        app(CheckInService::class)->checkIn($event, $ticket->code, $event->organizer);

        expect(app(CheckInService::class)->undo($event, $ticket->id, $event->organizer))->toBeTrue()
            ->and($ticket->fresh())
            ->checked_in_at->toBeNull()
            ->checked_in_by->toBeNull();
    });

    test('can check the same ticket in again after it was undone', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        $service = app(CheckInService::class);

        $service->checkIn($event, $ticket->code, $event->organizer);
        $service->undo($event, $ticket->id, $event->organizer);

        expect($service->checkIn($event, $ticket->code, $event->organizer)->status)->toBe(CheckInStatus::Success);
    });

    test('does nothing for a ticket of another event or one that was not checked in', function () {
        $event = Event::factory()->published()->create();
        $service = app(CheckInService::class);

        $other = issuedTicket(Event::factory()->published()->create());
        $otherEvent = $other->orderItem->order->event;
        $service->checkIn($otherEvent, $other->code, $otherEvent->organizer);
        $notCheckedIn = issuedTicket($event);

        expect($service->undo($event, $other->id, $event->organizer))->toBeFalse()
            ->and($other->fresh()->checked_in_at)->not->toBeNull()
            ->and($service->undo($event, $notCheckedIn->id, $event->organizer))->toBeFalse()
            ->and($service->undo($event, 999999, $event->organizer))->toBeFalse();
    });
});

describe('the numbers and the recent list', function () {
    test('count only the tickets of paid orders of this event, per ticket type', function () {
        $event = Event::factory()->published()->create();
        $service = app(CheckInService::class);

        $generalType = TicketType::factory()->for($event)->create(['name' => '一般']);
        $studentType = TicketType::factory()->for($event)->create(['name' => '学生']);
        $general = issuedTicket($event, ticketType: $generalType);
        issuedTicket($event, ticketType: $generalType);
        $student = issuedTicket($event, ticketType: $studentType);
        issuedTicket($event, OrderStatus::Pending, $generalType);
        issuedTicket($event, OrderStatus::Cancelled, $generalType);
        issuedTicket($event, OrderStatus::Refunded, $generalType);
        issuedTicket(Event::factory()->published()->create());
        $service->checkIn($event, $general->code, $event->organizer);
        $service->checkIn($event, $student->code, $event->organizer);

        $stats = $service->stats($event);

        expect($stats['issued'])->toBe(3)
            ->and($stats['checked_in'])->toBe(2)
            ->and($stats['by_ticket_type']->map(fn ($row) => [$row->name, (int) $row->checked_in, (int) $row->issued])->all())
            ->toEqual([['一般', 1, 2], ['学生', 1, 1]]);
    });

    test('are zero for an event without tickets', function () {
        $stats = app(CheckInService::class)->stats(Event::factory()->create());

        expect($stats['issued'])->toBe(0)
            ->and($stats['checked_in'])->toBe(0)
            ->and($stats['by_ticket_type'])->toBeEmpty();
    });

    test('list the latest check-ins of this event first, at most ten', function () {
        $event = Event::factory()->published()->create();
        $service = app(CheckInService::class);

        $tickets = collect(range(1, 12))->map(function (int $minute) use ($event, $service) {
            $ticket = issuedTicket($event);
            $this->travelTo(now()->startOfDay()->addMinutes($minute));
            $service->checkIn($event, $ticket->code, $event->organizer);

            return $ticket;
        });
        $other = issuedTicket($otherEvent = Event::factory()->published()->create());
        $service->checkIn($otherEvent, $other->code, $otherEvent->organizer);

        $recent = $service->recent($event);

        expect($recent)->toHaveCount(10)
            ->and($recent->first()->id)->toBe($tickets->last()->id)
            ->and($recent->pluck('id'))->not->toContain($other->id);
    });
});

describe('more about check-ins', function () {
    test('checks in only the ticket that was read when one order has several tickets', function () {
        $event = Event::factory()->published()->create();
        $order = Order::factory()->for($event)->paid()->create();
        $item = OrderItem::factory()->for($order)->for(TicketType::factory()->for($event))->create(['quantity' => 3]);
        $tickets = Ticket::factory()->for($item)->count(3)->create();

        $result = app(CheckInService::class)->checkIn($event, $tickets[1]->code, $event->organizer);

        expect($result->status)->toBe(CheckInStatus::Success)
            ->and($tickets[0]->fresh()->isCheckedIn())->toBeFalse()
            ->and($tickets[1]->fresh()->isCheckedIn())->toBeTrue()
            ->and($tickets[2]->fresh()->isCheckedIn())->toBeFalse();
    });

    test('keeps the ticket checked in but forgets the staff when the staff member is deleted', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        $staff = User::factory()->organizer()->create();
        app(CheckInService::class)->checkIn($event, $ticket->code, $staff);

        $staff->delete();

        expect($ticket->fresh())
            ->isCheckedIn()->toBeTrue()
            ->checked_in_by->toBeNull();
    });

    test('lowers the checked-in number when a check-in is undone', function () {
        $event = Event::factory()->published()->create();
        $service = app(CheckInService::class);
        $first = issuedTicket($event);
        $second = issuedTicket($event);
        $service->checkIn($event, $first->code, $event->organizer);
        $service->checkIn($event, $second->code, $event->organizer);

        $service->undo($event, $first->id, $event->organizer);

        expect($service->stats($event))
            ->checked_in->toBe(1)
            ->issued->toBe(2);
    });

    test('leaves tickets that were not checked in out of the recent list', function () {
        $event = Event::factory()->published()->create();
        $service = app(CheckInService::class);
        $checkedIn = issuedTicket($event);
        $notCheckedIn = issuedTicket($event);
        $service->checkIn($event, $checkedIn->code, $event->organizer);

        expect($service->recent($event)->pluck('id')->all())->toBe([$checkedIn->id])
            ->and($service->recent($event)->pluck('id'))->not->toContain($notCheckedIn->id);
    });

    test('puts the later ticket first in the recent list when two were checked in at the same time', function () {
        $event = Event::factory()->published()->create();
        $service = app(CheckInService::class);
        $first = issuedTicket($event);
        $second = issuedTicket($event);
        $this->freezeTime();
        $service->checkIn($event, $first->code, $event->organizer);
        $service->checkIn($event, $second->code, $event->organizer);

        expect($service->recent($event)->pluck('id')->all())->toBe([$second->id, $first->id]);
    });
});
