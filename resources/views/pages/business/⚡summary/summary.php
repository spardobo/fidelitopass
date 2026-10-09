<?php

use App\Models\Promotion;
use App\Support\BusinessSummary;
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
        $summary = app(BusinessSummary::class)->read(Auth::user());
        $active = $summary['currentPromotion'];
        $next = $summary['nextScheduled'];

        $view->with([
            ...$summary,
            'activePeriod' => $active === null ? null : $this->period($active),
            'nextPeriod' => $next === null ? null : $this->period($next),
            'preparation' => [
                'appearance' => $summary['appearancePrepared'],
                'promotion' => $summary['promotionPrepared'],
            ],
        ]);
    }

    /**
     * Formats the original inclusive local dates using the published timezone.
     *
     * @param  Promotion  $promotion  Server-resolved published or cancelled terms.
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
