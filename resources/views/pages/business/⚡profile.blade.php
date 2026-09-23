<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component {
    public string $name = '';

    public string $timezone = '';

    public function mount(): void
    {
        $business = Auth::user()->business;

        if ($business && request()->routeIs('business.create')) {
            $this->redirectRoute('business.edit');

            return;
        }

        if ($business) {
            $this->name = $business->name;
            $this->timezone = $business->timezone;
        }
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->hasVerifiedEmail(), 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        $user = Auth::user();
        $business = $user->business()->first();

        if ($business) {
            $business->update($validated);
        } else {
            // The unique owner constraint remains authoritative for concurrent submissions.
            $user->business()->create($validated);
        }

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<section class="mx-auto flex w-full max-w-2xl flex-col gap-6 px-4 py-6 sm:px-6">
    <header class="space-y-2">
        <flux:heading size="xl" level="1">{{ $this->name === '' ? 'Configurá tu negocio' : 'Perfil del negocio' }}</flux:heading>
        <flux:text>Indicá el nombre de tu negocio y su zona horaria para continuar.</flux:text>
    </header>

    <form wire:submit="save" class="flex flex-col gap-5">
        <flux:input wire:model="name" label="Nombre del negocio" type="text" autocomplete="organization" required autofocus maxlength="255" />

        <flux:field>
            <flux:label>Zona horaria</flux:label>
            <flux:select wire:model="timezone" required>
                <flux:select.option value="">Seleccioná una zona horaria</flux:select.option>
                @foreach (timezone_identifiers_list() as $identifier)
                    <flux:select.option :value="$identifier">{{ $identifier }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="timezone" />
        </flux:field>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <flux:button type="submit" variant="primary">Guardar negocio</flux:button>
            @if (request()->routeIs('business.edit'))
                <flux:button :href="route('dashboard')" wire:navigate>Volver al panel</flux:button>
            @endif
        </div>
    </form>
</section>
