<?php

use App\Support\SupportedTimezones;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    /**
     * Resolves and authorize the authenticated owner's Business.
     * Initialize editable name and timezone fields from the authorized record.
     *
     * @throws ModelNotFoundException When the authenticated user has no Business.
     * @throws AuthorizationException When the authenticated user cannot update the Business.
     */
    public function mount(): void
    {
        $business = Auth::user()->business()->firstOrFail();
        Gate::authorize('update', $business);

        $this->name = $business->name;
        $this->timezone = $business->timezone;
    }

    public function save(): void
    {
        $business = Auth::user()->business()->firstOrFail();
        Gate::authorize('update', $business);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', Rule::in(SupportedTimezones::identifiers())],
        ]);

        $business->update($validated);

        $this->redirectRoute('dashboard', navigate: true);
    }
};
