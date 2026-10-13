<?php

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function validEventInput(array $overrides = []): array
{
    $input = [
        'title' => 'まちの音楽祭',
        'description' => '商店街で開く小さな音楽祭です。',
        'venue' => '中央公園',
        'starts_at' => '2030-05-01T10:00',
        'ends_at' => '2030-05-01T12:00',
        ...$overrides,
    ];

    return collect($input)->mapWithKeys(fn ($value, $field) => ["form.$field" => $value])->all();
}

describe('access to organizer pages', function () {
    test('redirects guests to the login page', function (string $routeName) {
        $event = Event::factory()->create();

        $this->get(route($routeName, $event))->assertRedirect(route('login'));
    })->with(['organizer.events.index', 'organizer.events.create', 'organizer.events.edit']);

    test('returns 403 for customers and admins', function (string $state) {
        $event = Event::factory()->create();
        $user = $state === 'customer' ? User::factory()->create() : User::factory()->admin()->create();

        $this->actingAs($user)->get(route('organizer.events.index'))->assertForbidden();
        $this->actingAs($user)->get(route('organizer.events.create'))->assertForbidden();
        $this->actingAs($user)->get(route('organizer.events.edit', $event))->assertForbidden();
    })->with(['customer', 'admin']);
});

describe('event list', function () {
    test('lists only the organizer\'s own events', function () {
        $organizer = User::factory()->organizer()->create();
        $own = Event::factory()->for($organizer, 'organizer')->published()->create(['title' => '自分のイベント']);
        Event::factory()->create(['title' => '他人のイベント']);

        $this->actingAs($organizer)
            ->get(route('organizer.events.index'))
            ->assertOk()
            ->assertSee('自分のイベント')
            ->assertSee('公開中')
            ->assertSee(route('organizer.events.edit', $own))
            ->assertDontSee('他人のイベント');
    });
});

describe('creating an event', function () {
    test('saves a draft owned by the organizer and redirects to the edit page', function () {
        $organizer = User::factory()->organizer()->create();

        Livewire::actingAs($organizer)
            ->test('pages::organizer.events.create')
            ->set(validEventInput())
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('organizer.events.edit', Event::first()));

        $event = Event::sole();

        expect($event)
            ->title->toBe('まちの音楽祭')
            ->venue->toBe('中央公園')
            ->status->toBe(EventStatus::Draft)
            ->organizer_id->toBe($organizer->id)
            ->and($event->starts_at->format('Y-m-d H:i'))->toBe('2030-05-01 10:00')
            ->and($event->ends_at->format('Y-m-d H:i'))->toBe('2030-05-01 12:00');
    });

    test('rejects empty input with every field required', function () {
        Livewire::actingAs(User::factory()->organizer()->create())
            ->test('pages::organizer.events.create')
            ->call('save')
            ->assertHasErrors([
                'form.title' => 'required',
                'form.description' => 'required',
                'form.venue' => 'required',
                'form.starts_at' => 'required',
                'form.ends_at' => 'required',
            ]);

        expect(Event::count())->toBe(0);
    });

    test('rejects a title or venue longer than 100 characters', function (string $field) {
        Livewire::actingAs(User::factory()->organizer()->create())
            ->test('pages::organizer.events.create')
            ->set(validEventInput([$field => str_repeat('あ', 101)]))
            ->call('save')
            ->assertHasErrors(["form.$field" => 'max']);

        expect(Event::count())->toBe(0);
    })->with(['title', 'venue']);

    test('accepts a title and venue of exactly 100 characters', function () {
        Livewire::actingAs(User::factory()->organizer()->create())
            ->test('pages::organizer.events.create')
            ->set(validEventInput(['title' => str_repeat('あ', 100), 'venue' => str_repeat('い', 100)]))
            ->call('save')
            ->assertHasNoErrors();

        expect(Event::count())->toBe(1);
    });

    test('rejects an end time that is not after the start time', function (string $endsAt) {
        Livewire::actingAs(User::factory()->organizer()->create())
            ->test('pages::organizer.events.create')
            ->set(validEventInput(['ends_at' => $endsAt]))
            ->call('save')
            ->assertHasErrors(['form.ends_at' => 'after']);

        expect(Event::count())->toBe(0);
    })->with([
        'before the start' => '2030-05-01T09:00',
        'equal to the start' => '2030-05-01T10:00',
    ]);

    test('shows the validation message in Japanese with the field name', function () {
        Livewire::actingAs(User::factory()->organizer()->create())
            ->test('pages::organizer.events.create')
            ->set(validEventInput(['ends_at' => '2030-05-01T09:00']))
            ->call('save')
            ->assertSee('終了日時');
    });

    test('forbids saving when the user is no longer an organizer', function () {
        $organizer = User::factory()->organizer()->create();

        $component = Livewire::actingAs($organizer)->test('pages::organizer.events.create');

        $this->actingAs(User::factory()->create());

        $component->set(validEventInput())->call('save')->assertForbidden();

        expect(Event::count())->toBe(0);
    });
});

describe('editing an event', function () {
    test('prefills the form with the current values', function () {
        $event = Event::factory()->create([
            'title' => '春のマルシェ',
            'starts_at' => '2030-04-01 09:30:00',
            'ends_at' => '2030-04-01 15:00:00',
        ]);

        Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.edit', ['event' => $event])
            ->assertSet('form.title', '春のマルシェ')
            ->assertSet('form.starts_at', '2030-04-01T09:30')
            ->assertSet('form.ends_at', '2030-04-01T15:00');
    });

    test('returns 403 when another organizer opens the edit page', function () {
        $event = Event::factory()->create();

        $this->actingAs(User::factory()->organizer()->create())
            ->get(route('organizer.events.edit', $event))
            ->assertForbidden();
    });

    test('saves changes to the event', function () {
        $event = Event::factory()->published()->create();

        Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.edit', ['event' => $event])
            ->set(validEventInput(['title' => '更新後のタイトル']))
            ->call('save')
            ->assertHasNoErrors();

        expect($event->fresh())
            ->title->toBe('更新後のタイトル')
            ->venue->toBe('中央公園')
            ->status->toBe(EventStatus::Published);
    });

    test('saves every edited field including the dates', function () {
        $event = Event::factory()->create([
            'starts_at' => '2030-04-01 09:00:00',
            'ends_at' => '2030-04-01 11:00:00',
        ]);

        Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.edit', ['event' => $event])
            ->set(validEventInput([
                'description' => '説明を書き換えました。',
                'starts_at' => '2030-06-02T13:30',
                'ends_at' => '2030-06-02T16:45',
            ]))
            ->call('save')
            ->assertHasNoErrors();

        $updated = $event->fresh();

        expect($updated)
            ->description->toBe('説明を書き換えました。')
            ->organizer_id->toBe($event->organizer_id)
            ->and($updated->starts_at->format('Y-m-d H:i'))->toBe('2030-06-02 13:30')
            ->and($updated->ends_at->format('Y-m-d H:i'))->toBe('2030-06-02 16:45');
    });

    test('rejects invalid input and leaves the event unchanged', function () {
        $event = Event::factory()->create(['title' => '元のタイトル']);

        Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.edit', ['event' => $event])
            ->set(validEventInput(['title' => '', 'ends_at' => '2000-01-01T00:00']))
            ->call('save')
            ->assertHasErrors(['form.title' => 'required', 'form.ends_at' => 'after']);

        expect($event->fresh()->title)->toBe('元のタイトル');
    });
});

describe('publishing an event', function () {
    test('publishes a draft and returns a published event to draft', function () {
        $event = Event::factory()->has(TicketType::factory())->create();

        $component = Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.edit', ['event' => $event]);

        $component->call('togglePublish');
        expect($event->fresh()->status)->toBe(EventStatus::Published);

        $component->call('togglePublish');
        expect($event->fresh()->status)->toBe(EventStatus::Draft);
    });

    test('does not let the organizer republish an event unpublished by an admin', function () {
        $event = Event::factory()->unpublished()->create();

        Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.edit', ['event' => $event])
            ->call('togglePublish')
            ->assertSee('管理者により非公開にされています');

        expect($event->fresh()->status)->toBe(EventStatus::Unpublished);
    });
});

describe('deleting an event', function () {
    test('deletes the event and redirects to the list', function () {
        $event = Event::factory()->create();

        Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.edit', ['event' => $event])
            ->call('deleteEvent')
            ->assertRedirect(route('organizer.events.index'));

        expect(Event::count())->toBe(0);
    });
});

describe('authorization inside the methods that change data', function () {
    test('forbids another organizer from saving, toggling, or deleting after the page was opened', function (string $method) {
        $event = Event::factory()->published()->create(['title' => '元のタイトル']);

        $component = Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.edit', ['event' => $event])
            ->set(validEventInput(['title' => '乗っ取られたタイトル']));

        $this->actingAs(User::factory()->organizer()->create());

        $component->call($method)->assertForbidden();

        expect($event->fresh())
            ->title->toBe('元のタイトル')
            ->status->toBe(EventStatus::Published);
        expect(Event::whereKey($event->id)->exists())->toBeTrue();
    })->with(['save', 'togglePublish', 'deleteEvent']);
});

/**
 * 編集画面の「イベントの削除」ボタンが無効かどうかを返す。
 */
function deleteEventButtonIsDisabled(Testable $page): bool
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
    libxml_clear_errors();

    $button = (new DOMXPath($dom))->query("//button[@data-test='delete-event-button']")->item(0);

    expect($button)->not->toBeNull();

    return $button->hasAttribute('disabled');
}

describe('events that have orders', function () {
    test('keeps an event that has orders and tells the organizer instead of failing', function (OrderStatus $status) {
        $event = Event::factory()->create();
        Order::factory()->for($event)->create(['status' => $status]);

        Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.edit', ['event' => $event])
            ->call('deleteEvent')
            ->assertNoRedirect()
            ->assertDispatched('toast-show', fn (string $name, array $params) => str_contains(json_encode($params, JSON_UNESCAPED_UNICODE), '注文があるため、イベントは削除できません'));

        expect(Event::whereKey($event->id)->exists())->toBeTrue()
            ->and(Order::count())->toBe(1);
    })->with([
        'pending' => OrderStatus::Pending,
        'paid' => OrderStatus::Paid,
        'cancelled' => OrderStatus::Cancelled,
        'refunded' => OrderStatus::Refunded,
    ]);

    test('disables the delete button and explains why', function () {
        $event = Event::factory()->create();
        Order::factory()->for($event)->create();

        $page = Livewire::actingAs($event->organizer)->test('pages::organizer.events.edit', ['event' => $event]);

        expect(deleteEventButtonIsDisabled($page))->toBeTrue();

        $page->assertSee('注文があるため削除できません。');
    });

    test('keeps the delete button enabled for an event without orders', function () {
        $event = Event::factory()->create();

        $page = Livewire::actingAs($event->organizer)->test('pages::organizer.events.edit', ['event' => $event]);

        expect(deleteEventButtonIsDisabled($page))->toBeFalse();

        $page->assertDontSee('注文があるため削除できません。');
    });

    test('does not block deleting an event whose only orders belong to another event', function () {
        $event = Event::factory()->create();
        Order::factory()->create();

        Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.edit', ['event' => $event])
            ->call('deleteEvent')
            ->assertRedirect(route('organizer.events.index'));

        expect(Event::whereKey($event->id)->exists())->toBeFalse()
            ->and(Order::count())->toBe(1);
    });
});

describe('deleting an event: who may do it and what goes with it', function () {
    test('forbids another organizer before the order check, with or without orders', function (bool $hasOrders) {
        $event = Event::factory()->create();
        TicketType::factory()->for($event)->create();

        if ($hasOrders) {
            Order::factory()->for($event)->create();
        }

        $component = Livewire::actingAs($event->organizer)->test('pages::organizer.events.edit', ['event' => $event]);

        $this->actingAs(User::factory()->organizer()->create());

        $component->call('deleteEvent')
            ->assertForbidden()
            ->assertNotDispatched('toast-show');

        expect(Event::whereKey($event->id)->exists())->toBeTrue()
            ->and(TicketType::count())->toBe(1);
    })->with([
        'an event with orders' => true,
        'an event without orders' => false,
    ]);

    test('deletes the ticket types with the event and makes the sales page 404', function () {
        $event = Event::factory()->published()->create();
        TicketType::factory()->count(2)->for($event)->create();
        $survivor = TicketType::factory()->create();

        Livewire::actingAs($event->organizer)
            ->test('pages::organizer.events.edit', ['event' => $event])
            ->call('deleteEvent')
            ->assertRedirect(route('organizer.events.index'));

        expect(TicketType::count())->toBe(1)
            ->and(TicketType::whereKey($survivor->id)->exists())->toBeTrue();

        $this->actingAs($event->organizer)
            ->get(route('organizer.events.orders', $event->id))
            ->assertNotFound();
    });
});
