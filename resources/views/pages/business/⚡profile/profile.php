<?php

use App\Models\Business;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app'), Title('business.profile_title')] class extends Component
{
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
            Gate::authorize('update', $business);
            $this->name = $business->name;
            $this->timezone = $business->timezone;
        }
    }

    public function save(): void
    {
        $user = Auth::user();
        $business = $user?->business()->first();
        if ($business) {
            Gate::authorize('update', $business);
        } else {
            Gate::authorize('create', Business::class);
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        if ($business) {
            $business->update($validated);
        } else {
            // The unique owner constraint remains authoritative for concurrent submissions.
            $user->business()->create($validated);
        }

        $this->redirectRoute('dashboard', navigate: true);
    }
};
