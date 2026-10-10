<?php

use App\Models\Promotion;
use App\Models\User;
use App\Support\BusinessSummary;
use App\Support\PromotionDetail;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

new #[Layout('layouts::app'), Title('summary.title')] class extends Component
{
    #[Locked]
    public ?string $selectedPromotionId = null;

    /**
     * Reads fresh owner-authorized facts for this render without serialized tenant state.
     *
     * @param  View  $view  Native component view receiving the current read snapshot.
     */
    public function rendering(View $view): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 401);
        $summary = app(BusinessSummary::class)->read($actor);
        $primary = $summary['primaryPromotion'];
        $upcoming = $summary['upcomingPromotion'];
        $phase = $primary?->phase ?? 'none';
        $preparation = [
            'appearance' => $summary['appearancePrepared'],
            'promotion' => $summary['promotionPrepared'] || $summary['hasPromotionDraft'],
        ];
        if ($summary['statistics'] === 'unavailable') {
            Flux::toast(__('summary.load_error'), null, 5000, 'danger');
        }

        $view->with([
            ...$summary,
            'primaryPhase' => $phase,
            'primaryPeriod' => $primary === null ? null : $this->period($primary),
            'nextPeriod' => $upcoming === null ? null : $this->period($upcoming),
            'preparation' => $preparation,
            'preparationCompleted' => count(array_filter($preparation)),
        ]);
    }

    /**
     * Opens saved owned terms without exposing cancellation on Summary.
     *
     * @param  mixed  $publicId  Untrusted public identifier selected by a detail button.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     * @throws HttpException When the selection is invalid or unavailable.
     */
    public function showPromotionDetail(mixed $publicId): void
    {
        abort_unless(is_string($publicId) && Str::isUuid($publicId), 404);
        $this->selectedPromotionId = $publicId;
        unset($this->promotionDetail);

        $this->promotionDetail;
        Flux::modal('promotion-detail')->show();
    }

    /**
     * Clears the local selection after native modal dismissal without changing Summary facts.
     */
    public function dismissPromotionDetail(): void
    {
        $this->selectedPromotionId = null;
        unset($this->promotionDetail);
    }

    /**
     * Reloads owned terms on each request instead of hydrating saved Promotion data.
     *
     * @return array{promotion: Promotion, phase: string, start_date: string, end_date: string, extra_points: list<array{weekday: int, start_time: string|null, end_time: string|null, multiplier: int}>}|null Current saved detail or no selection.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     * @throws HttpException When authentication or the selection is unavailable.
     */
    #[Computed]
    public function promotionDetail(): ?array
    {
        if ($this->selectedPromotionId === null) {
            return null;
        }

        $actor = Auth::user();
        abort_unless($actor instanceof User, 401);

        return app(PromotionDetail::class)->read($actor, $this->selectedPromotionId);
    }

    /**
     * Formats frozen published inclusive dates in their snapshot timezone.
     *
     * @param  Promotion  $promotion  Server-resolved active or scheduled published terms.
     * @return string Local validity range, preserving exclusive-end calendar semantics.
     */
    private function period(Promotion $promotion): string
    {
        $timezone = $promotion->timezone_snapshot;

        return __('summary.period', [
            'start' => $promotion->starts_at->setTimezone($timezone)->format('d/m/Y'),
            'end' => $promotion->ends_at->setTimezone($timezone)->subDay()->format('d/m/Y'),
        ]);
    }
};
