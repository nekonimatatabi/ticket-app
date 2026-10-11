<?php

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;

test('shows the list to guests', function () {
    Event::factory()->published()->create(['title' => '公開イベント']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('公開イベント')
        ->assertSee('ログイン');
});

test('lists only published events that have not ended', function () {
    Event::factory()->published()->create(['title' => '表示されるイベント']);
    Event::factory()->create(['title' => '下書きイベント']);
    Event::factory()->unpublished()->create(['title' => '非公開イベント']);
    Event::factory()->published()->past()->create(['title' => '終了済みイベント']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('表示されるイベント')
        ->assertDontSee('下書きイベント')
        ->assertDontSee('非公開イベント')
        ->assertDontSee('終了済みイベント');
});

test('keeps an event in progress and drops one that ends right now', function () {
    $this->travelTo('2030-05-01 12:00:00');

    Event::factory()->published()->create([
        'title' => '開催中イベント',
        'starts_at' => '2030-05-01 11:00:00',
        'ends_at' => '2030-05-01 13:00:00',
    ]);
    Event::factory()->published()->create([
        'title' => 'ちょうど終了イベント',
        'starts_at' => '2030-05-01 10:00:00',
        'ends_at' => '2030-05-01 12:00:00',
    ]);

    $this->get(route('home'))
        ->assertSee('開催中イベント')
        ->assertDontSee('ちょうど終了イベント');
});

test('keeps an event that ends one second from now', function () {
    $this->travelTo('2030-05-01 12:00:00');
    Event::factory()->published()->create([
        'title' => 'あと1秒で終了イベント',
        'starts_at' => '2030-05-01 10:00:00',
        'ends_at' => '2030-05-01 12:00:01',
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('あと1秒で終了イベント');
});

test('does not list drafts or unpublished events even to their owner or an admin', function (string $viewerType) {
    $owner = User::factory()->organizer()->create();
    Event::factory()->for($owner, 'organizer')->create(['title' => '自分の下書き']);
    Event::factory()->for($owner, 'organizer')->unpublished()->create(['title' => '自分の非公開']);
    Event::factory()->published()->create(['title' => '公開イベント']);
    $viewer = $viewerType === 'owner' ? $owner : User::factory()->admin()->create();

    $this->actingAs($viewer)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('公開イベント')
        ->assertDontSee('自分の下書き')
        ->assertDontSee('自分の非公開');
})->with([
    'owner' => ['owner'],
    'admin' => ['admin'],
]);

test('orders events by the nearest start first', function () {
    Event::factory()->published()->create(['title' => '三番目イベント', 'starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)->addHours(2)]);
    Event::factory()->published()->create(['title' => '一番目イベント', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2)]);
    Event::factory()->published()->create(['title' => '二番目イベント', 'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)->addHours(2)]);

    $this->get(route('home'))
        ->assertSeeInOrder(['一番目イベント', '二番目イベント', '三番目イベント']);
});

test('links each card to the event page', function () {
    $event = Event::factory()->published()->create();

    $this->get(route('home'))->assertSee(route('events.show', $event), false);
});

test('shows the venue and the start time in the Japanese format', function () {
    app()->setLocale('ja');
    Event::factory()->published()->create([
        'venue' => '中央公園',
        'starts_at' => '2030-05-01 10:00:00',
        'ends_at' => '2030-05-01 12:00:00',
    ]);

    $this->get(route('home'))
        ->assertSee('中央公園')
        ->assertSee('5月1日(水) 10:00');
});

describe('lowest price', function () {
    test('shows the cheapest price with a tilde', function () {
        $event = Event::factory()->published()->create();
        TicketType::factory()->for($event)->create(['price' => 3000]);
        TicketType::factory()->for($event)->create(['price' => 1500]);

        $this->get(route('home'))
            ->assertSee('1,500円〜')
            ->assertDontSee('3,000円〜');
    });

    test('shows a single price with a tilde too', function () {
        $event = Event::factory()->published()->create();
        TicketType::factory()->for($event)->create(['price' => 3000]);

        $this->get(route('home'))->assertSee('3,000円〜');
    });

    test('shows free when the cheapest ticket costs 0 yen', function () {
        $event = Event::factory()->published()->create();
        TicketType::factory()->for($event)->create(['price' => 0]);
        TicketType::factory()->for($event)->create(['price' => 3000]);

        $this->get(route('home'))
            ->assertSee('無料')
            ->assertDontSee('3,000円〜')
            ->assertDontSee('0円〜');
    });

    test('shows no price for an event without ticket types', function () {
        Event::factory()->published()->create(['title' => '券種なしイベント']);

        $this->get(route('home'))
            ->assertSee('券種なしイベント')
            ->assertDontSee('円〜')
            ->assertDontSee('無料');
    });
});

describe('pagination', function () {
    beforeEach(function () {
        Event::factory()->published()->count(13)->sequence(fn ($sequence) => [
            'title' => sprintf('TITLE-%02d', $sequence->index + 1),
            'starts_at' => now()->addDays($sequence->index + 1),
            'ends_at' => now()->addDays($sequence->index + 1)->addHours(2),
        ])->create();
    });

    test('shows 12 events on the first page', function () {
        $this->get(route('home'))
            ->assertSee('TITLE-01')
            ->assertSee('TITLE-12')
            ->assertDontSee('TITLE-13');
    });

    test('fills the first page with published events even when drafts start earlier', function () {
        Event::factory()->count(2)->create(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);
        Event::factory()->unpublished()->count(2)->create(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);

        $response = $this->get(route('home'))->assertOk();

        expect(substr_count($response->getContent(), 'data-test="event-card"'))->toBe(12);
        $response->assertSee('TITLE-12')->assertDontSee('TITLE-13');
        $this->get(route('home', ['page' => 2]))->assertSee('TITLE-13');
    });

    test('shows the remaining event on the second page', function () {
        $this->get(route('home', ['page' => 2]))
            ->assertSee('TITLE-13')
            ->assertDontSee('TITLE-01')
            ->assertDontSee('TITLE-12');
    });
});

test('shows a message when there are no events', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('公開中のイベントはありません');
});

test('shows the same list to a logged-in customer', function () {
    Event::factory()->published()->create(['title' => '参加者にも見えるイベント']);

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee('参加者にも見えるイベント')
        ->assertSee('マイチケット');
});
