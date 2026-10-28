<?php

use App\Models\Business;
use App\Support\PersonalPromotionPresentation;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public', ['stackToasts' => false]), Title('personal-pass.page_title')] class extends Component
{
    private ?string $businessName = null;

    /** @var array{state: string, reward: ?string, description: ?string, points: int, target: ?int, visit_points: int, date: ?string, redeemed_date: ?string}|null */
    private ?array $presentation = null;

    private ?string $error = null;

    private int $httpStatus = 200;

    /**
     * Validates a native signed GET before reading any scoped personal fact.
     *
     * @param  Request  $request  Current native request; no bearer state is retained.
     * @param  string  $businessPublicId  Signed Business public UUID.
     * @param  string  $passPublicId  Signed pass public UUID, distinct from validation credentials.
     * @param  PersonalPromotionPresentation  $personalPromotionPresentation  Reads current authoritative facts.
     */
    public function mount(Request $request, string $businessPublicId, string $passPublicId, PersonalPromotionPresentation $personalPromotionPresentation): void
    {
        if (
            ! $request->isMethod('GET')
            || ! $request->routeIs('public.pass')
            || ! $request->hasValidSignature(absolute: false)
            || ! Str::isUuid($businessPublicId)
            || ! Str::isUuid($passPublicId)
        ) {
            $this->error = 'invalid';
            $this->httpStatus = 404;

            return;
        }

        try {
            $business = Business::query()
                ->where('public_id', $businessPublicId)
                ->first();
            $customerPass = $business?->customerPasses()
                ->where('public_id', $passPublicId)
                ->first();
            if (
                $business === null
                || $customerPass === null
            ) {
                $this->error = 'invalid';
                $this->httpStatus = 404;

                return;
            }

            $this->presentation = $personalPromotionPresentation->build($business, $customerPass);
            $this->businessName = $business->name;
        } catch (QueryException|PDOException|IntlException|InvalidArgumentException|UnexpectedValueException $exception) {
            // select diagnostics before logging; never retain URL, signature, SQL or native messages
            Log::error('customer_pass.consultation_failed', [
                'exception_class' => $exception::class,
                'source_file' => $exception->getFile(),
                'source_line' => $exception->getLine(),
                'source_frames' => array_map(static fn (array $frame): array => Arr::only($frame, ['file', 'line', 'class', 'function']), $exception->getTrace()),
            ]);

            $this->error = 'unavailable';
            $this->httpStatus = 503;
        }
    }

    /**
     * Refuses unnecessary updates before they can restore or read private facts.
     *
     * @return never No hydrated request is supported by this informational page.
     */
    public function hydrate(): never
    {
        abort(response('', 403, ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']));
    }

    /**
     * Supplies only visible facts and applies native full-page response privacy.
     *
     * @param  View  $view  Current native component view, not a public property snapshot.
     */
    public function rendering(View $view): void
    {
        $view->with([
            'businessName' => $this->businessName,
            'presentation' => $this->presentation,
            'error' => $this->error,
        ]);
        $view->response(function (Response $response): void {
            $response->setStatusCode($this->httpStatus);
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        });
    }
};
