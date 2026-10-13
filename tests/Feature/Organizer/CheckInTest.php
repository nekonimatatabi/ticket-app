<?php

use App\Enums\CheckInStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\User;
use App\Services\CheckInService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function openCheckInPage(User $user, Event $event): Testable
{
    return Livewire::actingAs($user)->test('pages::organizer.events.check-in', ['event' => $event]);
}

describe('who can open the check-in page', function () {
    test('lets the owner and admins open it', function () {
        $event = Event::factory()->published()->create();

        $this->actingAs($event->organizer)->get(route('organizer.events.check-in', $event))->assertOk()->assertSee('受付');
        $this->actingAs(User::factory()->admin()->create())->get(route('organizer.events.check-in', $event))->assertOk();
    });

    test('returns 403 to another organizer and to a customer', function (Closure $makeViewer) {
        $event = Event::factory()->published()->create();

        $this->actingAs($makeViewer->call($this))
            ->get(route('organizer.events.check-in', $event))
            ->assertForbidden();
    })->with([
        'another organizer' => fn () => User::factory()->organizer()->create(),
        'customer' => fn () => User::factory()->create(),
    ]);

    test('redirects guests to the login page', function () {
        $event = Event::factory()->published()->create();

        $this->get(route('organizer.events.check-in', $event))->assertRedirect(route('login'));
    });

    test('has a policy that allows only the owner and admins', function () {
        $event = Event::factory()->create();

        expect(Gate::forUser($event->organizer)->allows('checkIn', $event))->toBeTrue()
            ->and(Gate::forUser(User::factory()->admin()->create())->allows('checkIn', $event))->toBeTrue()
            ->and(Gate::forUser(User::factory()->organizer()->create())->allows('checkIn', $event))->toBeFalse()
            ->and(Gate::forUser(User::factory()->create())->allows('checkIn', $event))->toBeFalse();
    });

    test('loads the scanner script only on this page', function () {
        $event = Event::factory()->published()->create();

        $this->actingAs($event->organizer)
            ->get(route('organizer.events.check-in', $event))
            ->assertSee('ticket-scanner', false)
            ->assertSeeHtml('data-test="start-scan-button"');

        $this->actingAs($event->organizer)
            ->get(route('organizer.events.orders', $event))
            ->assertDontSee('ticket-scanner', false);
    });
});

describe('checking in on the page', function () {
    test('shows the numbers of the event and updates them after a check-in', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        issuedTicket($event);

        openCheckInPage($event->organizer, $event)
            ->assertSeeHtml('data-test="check-in-count"')
            ->assertSeeInOrder(['受付済み / 発行済み', '0 / 2枚'])
            ->set('code', $ticket->code)
            ->call('submitCode')
            ->assertSee('1 / 2枚')
            ->assertDontSee('0 / 2枚');
    });

    test('checks a ticket in from the input field, shows who it was, and clears the field', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event, ticketType: '学生', buyer: User::factory()->create(['name' => '山田 花子']));

        openCheckInPage($event->organizer, $event)
            ->set('code', $ticket->code)
            ->call('submitCode')
            ->assertSet('code', '')
            ->assertSeeHtml('data-status="success"')
            ->assertSee('受付しました')
            ->assertSee('学生')
            ->assertSee('山田 花子様');

        expect($ticket->fresh()->checked_in_by)->toBe($event->organizer->id);
    });

    test('checks a ticket in from a code sent by the camera', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);

        openCheckInPage($event->organizer, $event)
            ->call('checkIn', $ticket->code)
            ->assertSee('受付しました');

        expect($ticket->fresh()->isCheckedIn())->toBeTrue();
    });

    test('warns when the ticket was already checked in, with the time and the person', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        $first = User::factory()->organizer()->create(['name' => '最初の担当者']);
        $page = openCheckInPage($event->organizer, $event);
        app(CheckInService::class)->checkIn($event, $ticket->code, $first);

        $page->call('checkIn', $ticket->code)
            ->assertSeeHtml('data-status="already_checked_in"')
            ->assertSee('すでに受付済みです')
            ->assertSee('最初の担当者');
    });

    test('shows the reason for each ticket that cannot be checked in', function (string $case, CheckInStatus $status) {
        $event = Event::factory()->published()->create();

        $code = match ($case) {
            'nobody has it' => '01HZX3K9F2M7N8P4Q5R6S7T8V1',
            'not a code' => 'hello',
            'another event' => issuedTicket(Event::factory()->published()->create())->code,
            'not paid' => issuedTicket($event, OrderStatus::Pending)->code,
        };

        openCheckInPage($event->organizer, $event)
            ->call('checkIn', $code)
            ->assertSeeHtml('data-status="'.$status->value.'"')
            ->assertSee($status->label());
    })->with([
        'nobody has it' => ['nobody has it', CheckInStatus::NotFound],
        'not a code' => ['not a code', CheckInStatus::NotFound],
        'another event' => ['another event', CheckInStatus::WrongEvent],
        'not paid' => ['not paid', CheckInStatus::NotPaid],
    ]);

    test('does not show the buyer of a ticket of another event', function () {
        $event = Event::factory()->published()->create();
        $other = issuedTicket(Event::factory()->published()->create(), buyer: User::factory()->create(['name' => '別イベントの購入者']));

        openCheckInPage($event->organizer, $event)
            ->call('checkIn', $other->code)
            ->assertDontSee('別イベントの購入者');
    });

    test('shows the latest check-ins and undoes one after confirmation', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event, buyer: User::factory()->create(['name' => '山田 花子']));

        $page = openCheckInPage($event->organizer, $event)->call('checkIn', $ticket->code);

        $page->assertSeeHtml('data-test="recent-row"')
            ->assertSeeHtml('wire:confirm="この受付を取り消しますか？"')
            ->call('undoCheckIn', $ticket->id)
            ->assertSee('受付を取り消しました')
            ->assertSeeHtml('data-test="no-recent"');

        expect($ticket->fresh()->isCheckedIn())->toBeFalse();
    });

    test('cannot undo the check-in of a ticket of another event', function () {
        $event = Event::factory()->published()->create();
        $otherEvent = Event::factory()->published()->create();
        $other = issuedTicket($otherEvent);
        app(CheckInService::class)->checkIn($otherEvent, $other->code, $otherEvent->organizer);

        openCheckInPage($event->organizer, $event)
            ->call('undoCheckIn', $other->id)
            ->assertSee('取り消せるチケットが見つかりませんでした');

        expect($other->fresh()->isCheckedIn())->toBeTrue();
    });
});

describe('what people cannot do by calling the page directly', function () {
    test('forbids an actor who is not the owner from checking in through an opened page', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        $page = openCheckInPage($event->organizer, $event);

        $this->actingAs(User::factory()->organizer()->create());

        $page->call('checkIn', $ticket->code)->assertForbidden();

        expect($ticket->fresh()->isCheckedIn())->toBeFalse();
    });

    test('does not let the result be changed from the browser', function (string $property, mixed $value) {
        $event = Event::factory()->published()->create();

        expect(fn () => openCheckInPage($event->organizer, $event)->set($property, $value))
            ->toThrow('Cannot update locked property');
    })->with([
        'result status' => ['resultStatus', 'success'],
        'result ticket' => ['resultTicketId', 1],
        'notice' => ['notice', 'hello'],
    ]);

    test('stops after too many check-ins in a minute and checks nothing in', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        foreach (range(1, 120) as $attempt) {
            RateLimiter::hit("check-in:{$event->organizer->id}", 60);
        }

        openCheckInPage($event->organizer, $event)
            ->call('checkIn', $ticket->code)
            ->assertSee('操作が多すぎます')
            ->assertDontSeeHtml('data-test="check-in-result"');

        expect($ticket->fresh()->isCheckedIn())->toBeFalse();
    });
});

test('links to the check-in page from the organizer menu', function () {
    $event = Event::factory()->published()->create();

    $this->actingAs($event->organizer)
        ->get(route('organizer.events.index'))
        ->assertSee(route('organizer.events.check-in', $event), false)
        ->assertSeeHtml('data-test="event-check-in-button"');
});

describe('more about the check-in page', function () {
    test('lets an admin who does not own the event check a ticket in', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        $admin = User::factory()->admin()->create();

        openCheckInPage($admin, $event)
            ->call('checkIn', $ticket->code)
            ->assertSee('受付しました');

        expect($ticket->fresh()->checked_in_by)->toBe($admin->id);
    });

    test('shows the undo button of the result only after a success', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        $page = openCheckInPage($event->organizer, $event);

        $page->call('checkIn', $ticket->code)->assertSeeHtml('data-test="undo-result-button"');
        $page->call('checkIn', $ticket->code)
            ->assertSeeHtml('data-status="already_checked_in"')
            ->assertDontSeeHtml('data-test="undo-result-button"');
        $page->call('checkIn', 'hello')->assertDontSeeHtml('data-test="undo-result-button"');
    });

    test('accepts a typed code with spaces and lowercase letters', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);

        openCheckInPage($event->organizer, $event)
            ->set('code', '  '.strtolower($ticket->code).' ')
            ->call('submitCode')
            ->assertSeeHtml('data-status="success"');

        expect($ticket->fresh()->isCheckedIn())->toBeTrue();
    });

    test('says not found for an empty input', function () {
        $event = Event::factory()->published()->create();

        openCheckInPage($event->organizer, $event)
            ->set('code', '')
            ->call('submitCode')
            ->assertSeeHtml('data-status="not_found"');
    });

    test('shows at most ten recent check-ins and only those of this event', function () {
        $event = Event::factory()->published()->create();
        $otherEvent = Event::factory()->published()->create();
        $service = app(CheckInService::class);
        foreach (range(1, 12) as $index) {
            $service->checkIn($event, issuedTicket($event)->code, $event->organizer);
        }
        $service->checkIn($otherEvent, issuedTicket($otherEvent, buyer: User::factory()->create(['name' => '別イベントの購入者']))->code, $otherEvent->organizer);

        $html = openCheckInPage($event->organizer, $event)
            ->assertDontSee('別イベントの購入者')
            ->html();

        expect(substr_count($html, 'data-test="recent-row"'))->toBe(10);
    });

    test('does not let the rate limit of one user stop another user', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        $admin = User::factory()->admin()->create();
        foreach (range(1, 120) as $attempt) {
            RateLimiter::hit("check-in:{$event->organizer->id}", 60);
        }

        openCheckInPage($admin, $event)
            ->call('checkIn', $ticket->code)
            ->assertSee('受付しました');
    });

    test('forbids an actor who is not the owner from undoing through an opened page', function () {
        $event = Event::factory()->published()->create();
        $ticket = issuedTicket($event);
        app(CheckInService::class)->checkIn($event, $ticket->code, $event->organizer);
        $page = openCheckInPage($event->organizer, $event);

        $this->actingAs(User::factory()->organizer()->create());

        $page->call('undoCheckIn', $ticket->id)->assertForbidden();

        expect($ticket->fresh()->isCheckedIn())->toBeTrue();
    });
});
