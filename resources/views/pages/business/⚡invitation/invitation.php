<?php

use App\Models\Business;
use App\Models\User;
use App\Support\BusinessAcquisitionQr;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

new #[Layout('layouts::app'), Title('invitation.title')] class extends Component
{
    /** Supplies fresh owner context and preparation artwork without domain mutations.
     * @param  View  $view  Native view receiving authoritative eligibility and generated artwork.
     */
    public function rendering(View $view): void
    {
        $business = $this->ownedBusiness();
        $svg = null;
        if ($business->pass_background_color !== null && ! $this->getErrorBag()->has('qr')) {
            $svg = $this->generateQr($business);
        }

        $view->with([
            'business' => $business,
            'appearanceSaved' => $business->pass_background_color !== null,
            'qrSvg' => $svg,
            'qrUrl' => $svg !== null ? app(BusinessAcquisitionQr::class)->url($business) : null,
        ]);
    }

    /** Downloads preparation artwork after rechecking current owner, verification and saved appearance.
     * @return StreamedResponse|null SVG download, or no download when generation failed recoverably.
     */
    public function downloadQr(): ?StreamedResponse
    {
        $business = $this->ownedBusiness();
        abort_unless($business->pass_background_color !== null, 403);
        $svg = $this->generateQr($business);
        if ($svg === null) {
            return null;
        }

        return response()->streamDownload(fn () => print ($svg), 'fidelitopass-qr-'.$business->public_id.'.svg', [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    /** Clears only QR-generation feedback so native rendering retries with current server context. */
    public function retryQr(): void
    {
        $this->ownedBusiness();
        $this->resetErrorBag('qr');
    }

    /** Resolves and authorizes the current actor's Business without serialized tenant authority.
     * @return Business Fresh actor-owned Business.
     *
     * @throws AuthorizationException When the actor is not a verified owner.
     * @throws ModelNotFoundException When the actor has no Business.
     * @throws HttpException When there is no authenticated actor.
     */
    private function ownedBusiness(): Business
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 401);

        $business = $actor->business()->firstOrFail();
        Gate::authorize('update', $business);

        return $business;
    }

    /** Converts a generation outage into safe, retryable UI feedback without replacing public identity.
     * @param  Business  $business  Fresh owner-authorized Business with saved appearance.
     * @return string|null SVG artwork, or null after reporting the generation exception.
     */
    private function generateQr(Business $business): ?string
    {
        try {
            return app(BusinessAcquisitionQr::class)->svg($business);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('qr', __('invitation.generation_failed'));

            return null;
        }
    }
};
