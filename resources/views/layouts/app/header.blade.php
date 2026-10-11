@php
    $user = auth()->user();

    $navItems = [
        ['label' => 'イベント一覧', 'href' => route('home'), 'current' => request()->routeIs('home', 'events.*')],
    ];

    if ($user) {
        $navItems[] = ['label' => 'マイチケット', 'href' => url('/orders'), 'current' => request()->is('orders', 'orders/*')];

        if ($user->isOrganizer()) {
            $navItems[] = ['label' => '主催者メニュー', 'href' => url('/organizer/events'), 'current' => request()->is('organizer/*')];
        }

        if ($user->isAdmin()) {
            $navItems[] = ['label' => 'イベント管理', 'href' => url('/admin/events'), 'current' => request()->is('admin/events', 'admin/events/*')];
            $navItems[] = ['label' => 'ユーザー管理', 'href' => url('/admin/users'), 'current' => request()->is('admin/users', 'admin/users/*')];
        }

        $navItems[] = ['label' => '設定', 'href' => route('profile.edit'), 'current' => request()->is('settings', 'settings/*')];
    }

    $guestItems = [
        ['label' => 'ログイン', 'href' => route('login'), 'current' => request()->routeIs('login')],
        ['label' => '会員登録', 'href' => route('register'), 'current' => request()->routeIs('register')],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('home') }}" wire:navigate />

            <flux:navbar class="-mb-px max-lg:hidden" data-test="main-navigation">
                @foreach ($navItems as $item)
                    <flux:navbar.item :href="$item['href']" :current="$item['current']" wire:navigate>
                        {{ $item['label'] }}
                    </flux:navbar.item>
                @endforeach
            </flux:navbar>

            <flux:spacer />

            @auth
                <x-desktop-user-menu />
            @else
                <flux:navbar class="max-lg:hidden" data-test="guest-navigation">
                    @foreach ($guestItems as $item)
                        <flux:navbar.item :href="$item['href']" :current="$item['current']" wire:navigate>
                            {{ $item['label'] }}
                        </flux:navbar.item>
                    @endforeach
                </flux:navbar>
            @endauth
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('home') }}" wire:navigate />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                @foreach ($navItems as $item)
                    <flux:sidebar.item :href="$item['href']" :current="$item['current']" wire:navigate>
                        {{ $item['label'] }}
                    </flux:sidebar.item>
                @endforeach

                @guest
                    @foreach ($guestItems as $item)
                        <flux:sidebar.item :href="$item['href']" :current="$item['current']" wire:navigate>
                            {{ $item['label'] }}
                        </flux:sidebar.item>
                    @endforeach
                @endguest
            </flux:sidebar.nav>

            <flux:spacer />

            @auth
                <x-desktop-user-menu />
            @endauth
        </flux:sidebar>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
