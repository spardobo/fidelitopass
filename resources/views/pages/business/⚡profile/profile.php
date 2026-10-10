<?php

use App\Support\SupportedTimezones;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

    /**
     * Returns the persisted Business name without reading editable form state.
     *
     * @return string|null Saved Business name, or null when the account has no Business.
     */
    #[Computed]
    public function businessName(): ?string
    {
        return Auth::user()->business()->value('name');
    }

    /**
     * Saves the authorized Business fields and delivers feedback across internal navigation.
     *
     * @throws AuthorizationException When the authenticated user cannot update the Business.
     * @throws ModelNotFoundException When the authenticated user no longer owns a Business.
     * @throws ValidationException When the submitted fields are invalid.
     */
    public function save(): void
    {
        $business = Auth::user()->business()->firstOrFail();
        Gate::authorize('update', $business);

        try {
            $validated = $this->validate([
                'name' => ['required', 'string', 'max:255'],
                'timezone' => ['required', 'string', Rule::in(SupportedTimezones::identifiers())],
            ]);

            $business->update($validated);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: __('account-feedback.validation'));

            throw $exception;
        } catch (AuthorizationException|AuthenticationException|ModelNotFoundException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            Flux::toast(variant: 'danger', text: __('account-feedback.unexpected'));

            return;
        }

        Flux::toast(variant: 'success', text: __('account-feedback.business_saved'));
        $this->redirectRoute('dashboard', navigate: true);
    }
};
