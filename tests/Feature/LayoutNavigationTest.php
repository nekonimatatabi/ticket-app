<?php

use App\Models\User;
use Illuminate\Testing\TestView;

function renderAppLayout(): TestView
{
    return test()->blade('<x-layouts::app>本文</x-layouts::app>');
}

test('renders the header layout for guests without a user', function () {
    renderAppLayout()
        ->assertSee('本文')
        ->assertSee('イベント一覧')
        ->assertSee('ログイン')
        ->assertSee('会員登録')
        ->assertSee(route('home'), false)
        ->assertSee(route('login'), false)
        ->assertSee(route('register'), false);
});

test('hides member-only navigation from guests', function (string $label) {
    renderAppLayout()->assertDontSee($label);
})->with(['マイチケット', '設定', '主催者メニュー', 'イベント管理', 'ユーザー管理']);

test('shows the member navigation to a customer', function () {
    $this->actingAs(User::factory()->create());

    renderAppLayout()
        ->assertSee('イベント一覧')
        ->assertSee('マイチケット')
        ->assertSee(url('/orders'), false)
        ->assertSee('設定')
        ->assertSee(route('profile.edit'), false);
});

test('hides guest links and role menus from a customer', function (string $label) {
    $this->actingAs(User::factory()->create());

    renderAppLayout()->assertDontSee($label);
})->with(['会員登録', '主催者メニュー', 'イベント管理', 'ユーザー管理']);

test('adds the organizer menu for an organizer only', function () {
    $this->actingAs(User::factory()->organizer()->create());

    renderAppLayout()
        ->assertSee('イベント一覧')
        ->assertSee('マイチケット')
        ->assertSee('設定')
        ->assertSee('主催者メニュー')
        ->assertSee(url('/organizer/events'), false)
        ->assertDontSee('イベント管理')
        ->assertDontSee('ユーザー管理');
});

test('adds the event and user management menus for an admin only', function () {
    $this->actingAs(User::factory()->admin()->create());

    renderAppLayout()
        ->assertSee('イベント一覧')
        ->assertSee('マイチケット')
        ->assertSee('設定')
        ->assertSee('イベント管理')
        ->assertSee(url('/admin/events'), false)
        ->assertSee('ユーザー管理')
        ->assertSee(url('/admin/users'), false)
        ->assertDontSee('主催者メニュー');
});

test('shows the header navigation on pages that use the layout', function () {
    $organizer = User::factory()->organizer()->create();

    $this->actingAs($organizer)
        ->get(route('organizer.events.index'))
        ->assertOk()
        ->assertSee('主催者メニュー')
        ->assertSee('マイチケット');
});
