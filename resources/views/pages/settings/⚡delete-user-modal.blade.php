<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Deletes the authenticated account before logout and flashes fixed landing feedback.
     *
     * @param Logout $logout Native action that invalidates the authenticated session after deletion.
     *
     * @throws ValidationException When the current password is rejected.
     */
    public function deleteUser(Logout $logout): void
    {
        try {
            $this->validate([
                'password' => $this->currentPasswordRules(),
            ]);

            // Logout rotates the guard model's remember token; preserve its existing-record state.
            $user = clone Auth::user();

            if (! $user->delete()) {
                throw new RuntimeException('Account deletion was not completed.');
            }
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

        $logout();
        Session::flash('account.feedback', 'deleted');

        $this->redirect('/');
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable :closable="false" class="app-theme min-w-0! max-w-lg rounded-[20px]! bg-app-surface! ring-app-border!">
    <form method="POST" wire:submit="deleteUser" novalidate class="space-y-6">
        <div class="space-y-2 pe-12">
            <flux:heading size="lg" class="app-role-section! text-app-ink!">
                {{ __('Are you sure you want to delete your account?') }}
            </flux:heading>

            <flux:subheading class="app-note-with-icon app-description text-app-danger-ink!">
                <span aria-hidden="true" class="inline-flex h-5 w-4 shrink-0 items-center justify-center">
                    <flux:icon.exclamation-triangle variant="outline" class="size-4" />
                </span>
                <span>
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                </span>
            </flux:subheading>
        </div>

        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            class:input="app-input-password"
            label:class="app-label"
            error:class="app-error"
        >
            <x-slot name="iconTrailing">
                <flux:input.viewable class="app-button-toggle" />
            </x-slot>
        </flux:input>

        <div class="flex flex-wrap justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled" class="app-button-secondary">
                    {{ __('Cancel') }}
                </flux:button>
            </flux:modal.close>

            <flux:button
                variant="danger"
                type="submit"
                data-test="confirm-delete-user-button"
                class="app-button"
            >
                {{ __('Delete account') }}
            </flux:button>
        </div>
    </form>

    <div class="absolute end-4 top-4">
        <flux:modal.close>
            <flux:button variant="ghost" icon="x-mark" icon:variant="outline" :aria-label="__('Close modal')" class="app-button-toggle" />
        </flux:modal.close>
    </div>
</flux:modal>
