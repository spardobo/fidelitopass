<?php

use App\Models\Promotion;
use App\Models\User;
use App\Support\BusinessSummary;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app'), Title('summary.title')] class extends Component
{
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
        $next = $summary['nextScheduled'];
        $upcoming = $next?->id === $primary?->id ? null : $next;
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
            'upcomingPromotion' => $upcoming,
            'nextPeriod' => $upcoming === null ? null : $this->period($upcoming),
            'preparation' => $preparation,
            'preparationCompleted' => count(array_filter($preparation)),
        ]);
    }

    /**
     * Formats draft local dates as entered, or frozen published inclusive dates in their snapshot timezone.
     *
     * @param  Promotion  $promotion  Server-resolved draft, published or cancelled terms.
     * @return string Local validity range, preserving exclusive-end calendar semantics.
     */
    private function period(Promotion $promotion): string
    {
        if ($promotion->phase === 'draft') {
            return __('summary.period', [
                'start' => $promotion->local_start_date->format('d/m/Y'),
                'end' => $promotion->local_end_date->format('d/m/Y'),
            ]);
        }

        $timezone = $promotion->timezone_snapshot;

        return __('summary.period', [
            'start' => $promotion->starts_at->setTimezone($timezone)->format('d/m/Y'),
            'end' => $promotion->ends_at->setTimezone($timezone)->subDay()->format('d/m/Y'),
        ]);
    }
};
