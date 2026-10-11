<?php

use App\Models\Business;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public'), Title('join.page_title')] class extends Component
{
    #[Locked]
    public string $publicId;

    /** Retains only the route's public Business identity, never a private pass credential.
     * @param  string  $public_id  UUID constrained by the public acquisition route.
     */
    public function mount(string $public_id): void
    {
        $this->publicId = $public_id;
    }

    /** Reloads only the public Business identity and saved appearance.
     * @param  View  $view  Native view receiving Business appearance for an independent fictional example.
     *
     * @throws ModelNotFoundException When the public Business no longer exists.
     */
    public function rendering(View $view): void
    {
        $business = Business::query()->where('public_id', $this->publicId)->firstOrFail();
        $view->with([
            'businessName' => $business->name,
            'backgroundColor' => $business->pass_background_color,
        ]);
    }
};
