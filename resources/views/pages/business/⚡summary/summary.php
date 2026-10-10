<?php

use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Support\BusinessSummary;
use App\Support\PromotionDetail;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

new #[Layout('layouts::app'), Title('summary.title')] class extends Component
{
    use WithPagination;

    #[Locked]
    public ?string $selectedPromotionId = null;

    #[Locked]
    public string $promotionDetailOrigin = 'primary';

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
            'historyPromotions' => $this->historyPromotions($summary['business'], $summary['asOf']),
        ]);
    }

    /**
     * Opens saved owned terms without exposing cancellation on Summary.
     *
     * @param  mixed  $publicId  Untrusted public identifier selected by a detail button.
     * @param  string  $origin  Allowlisted Summary region used only for native focus restoration.
     *
     * @throws AuthorizationException When the actor cannot update the Business.
     * @throws ModelNotFoundException When the actor has no Business.
     * @throws HttpException When the selection is invalid or unavailable.
     */
    public function showPromotionDetail(mixed $publicId, string $origin = 'primary'): void
    {
        abort_unless(is_string($publicId) && Str::isUuid($publicId), 404);
        abort_unless(in_array($origin, ['primary', 'upcoming', 'history'], true), 404);
        $this->promotionDetailOrigin = $origin;
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
     * Returns owned history at the Summary snapshot instant without a second phase clock.
     *
     * @param  Business  $business  Business freshly resolved and authorized by the Summary reader.
     * @param  CarbonImmutable  $instant  Authoritative PostgreSQL instant captured for this render.
     * @return LengthAwarePaginator<int, array{promotion: Promotion, period: array{start: string, end: string}, phase: string}> Three historical rows per independent history page, latest original start first.
     */
    private function historyPromotions(Business $business, CarbonImmutable $instant): LengthAwarePaginator
    {
        return $business->promotions()
            ->where(function ($query) use ($instant): void {
                $query->where('status', PromotionStatus::Cancelled->value)
                    ->orWhere(fn ($published) => $published
                        ->where('status', PromotionStatus::Published->value)
                        ->where('ends_at', '<=', $instant));
            })
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate(3, ['*'], 'historyPage')
            ->through(fn (Promotion $promotion): array => [
                'promotion' => $promotion,
                'period' => $this->period($promotion),
                'phase' => $promotion->status === PromotionStatus::Cancelled ? 'cancelled' : 'ended',
            ]);
    }

    /**
     * Returns frozen published inclusive ISO dates in their snapshot timezone.
     *
     * @param  Promotion  $promotion  Server-resolved published or cancelled terms.
     * @return array{start: string, end: string} ISO calendar endpoints, preserving exclusive-end semantics.
     */
    private function period(Promotion $promotion): array
    {
        $timezone = $promotion->timezone_snapshot;

        return [
            'start' => $promotion->starts_at->setTimezone($timezone)->format('Y-m-d'),
            'end' => $promotion->ends_at->setTimezone($timezone)->subDay()->format('Y-m-d'),
        ];
    }
};
