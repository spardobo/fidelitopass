<?php

use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
/* @chisel-email-verification */
use Illuminate\Contracts\Auth\MustVerifyEmail;
/* @end-chisel-email-verification */
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

new #[Title('Profile settings')] class extends Component
{
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    /**
     * Initializes the editable name and email fields from the authenticated user without persisting changes.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        try {
            $validated = $this->validate($this->profileRules($user->id));

            $user->fill($validated);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();
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

        Flux::toast(variant: 'success', text: __('Profile updated.'));
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

    /* @chisel-email-verification */
    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        try {
            $user->sendEmailVerificationNotification();
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

        Flux::toast(variant: 'success', text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
    /* @end-chisel-email-verification */
}; ?>

<section class="app-theme app-workspace">
    @include('partials.settings-heading', ['businessName' => $this->businessName])

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" novalidate class="my-6 w-full space-y-6">
            <flux:input
                wire:model="name"
                :label="__('Name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                class:input="app-input"
                label:class="app-label"
                error:class="app-error"
            />

            <div>
                <flux:input
                    wire:model="email"
                    :label="__('Email')"
                    type="email"
                    required
                    autocomplete="email"
                    class:input="app-input"
                    label:class="app-label"
                    error:class="app-error"
                />

                {{-- @chisel-email-verification --}}
                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="app-note-with-icon app-role-support! text-app-ink-help! mt-4">
                            <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                                <flux:icon.information-circle variant="outline" class="size-4" />
                            </span>
                            <span>
                                {{ __('Your email address is unverified.') }}

                                <flux:button variant="ghost" wire:click.prevent="resendVerificationNotification" class="app-button text-app-accent-text! underline underline-offset-4">
                                    {{ __('Click here to re-send the verification email.') }}
                                </flux:button>
                            </span>
                        </flux:text>

                    </div>
                @endif
                {{-- @end-chisel-email-verification --}}
            </div>

            <div class="flex items-center justify-end gap-4">
                <flux:button variant="primary" type="submit" data-test="update-profile-button" class="app-button-primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>

        {{-- @chisel-email-verification --}}
        @if ($this->showDeleteUser)
        {{-- @end-chisel-email-verification --}}
            <livewire:pages::settings.delete-user-form />
        {{-- @chisel-email-verification --}}
        @endif
        {{-- @end-chisel-email-verification --}}
    </x-pages::settings.layout>
</section>
