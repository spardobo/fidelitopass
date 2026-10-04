<flux:dropdown position="bottom" align="end">
    <flux:button variant="ghost" aria-label="{{ __('Open account menu') }}" data-test="sidebar-menu-button" class="app-button size-11! p-0! rounded-full!">
        <flux:avatar :name="auth()->user()->name" circle aria-hidden="true" class="size-11 bg-app-emphasis! text-app-accent-text!" />
    </flux:button>

    <flux:menu class="app-theme bg-app-surface! border-app-border! max-w-[calc(100vw-2rem)]">
        <div class="grid gap-1 px-2 py-2 text-start">
            <flux:heading class="app-role-action! text-app-ink! break-words">
                {{ auth()->user()->name }}
            </flux:heading>
            <flux:text class="app-role-support! text-app-ink-secondary! break-all">
                {{ auth()->user()->email }}
            </flux:text>
        </div>
        <flux:menu.separator />

        @foreach ([
            ['route' => 'business.edit', 'label' => __('business.profile_title'), 'icon' => 'building-storefront'],
            ['route' => 'profile.edit', 'label' => __('Profile'), 'icon' => 'user'],
            ['route' => 'security.edit', 'label' => __('Security'), 'icon' => 'shield-check'],
        ] as $destination)
            <flux:menu.item
                :href="route($destination['route'])"
                :icon="$destination['icon']"
                wire:navigate
                :aria-current="request()->routeIs($destination['route']) ? 'page' : null"
                class="app-button text-app-ink! aria-[current=page]:text-app-accent-text!"
            >
                {{ $destination['label'] }}
            </flux:menu.item>
        @endforeach

        <flux:menu.separator />
        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" data-test="logout-button" class="app-button w-full cursor-pointer text-app-ink!">
                {{ __('Log out') }}
            </flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>
