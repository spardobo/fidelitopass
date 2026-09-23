<x-slot:description>{{ __('landing.page_description') }}</x-slot:description>

<div class="overflow-x-clip">
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-full focus:bg-accent focus:px-5 focus:py-3 focus:text-zinc-950">{{ __('landing.navigation.skip_to_content') }}</a>
    <header class="border-b border-zinc-800/80">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-5 sm:px-8">
            <a href="#inicio" class="text-xl font-black tracking-tight focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent" aria-label="{{ __('landing.navigation.back_to_top_label') }}">Fidelito<span class="text-accent">Pass</span><span aria-hidden="true" class="ml-1 text-accent">✳</span></a>
            <nav class="hidden items-center gap-7 text-sm text-zinc-300 md:flex" aria-label="{{ __('landing.navigation.page_sections') }}">
                <a class="hover:text-accent focus-visible:outline-2 focus-visible:outline-accent" href="#como-funciona">{{ __('landing.navigation.how_it_works') }}</a>
                <a class="hover:text-accent focus-visible:outline-2 focus-visible:outline-accent" href="#retos">{{ __('landing.navigation.challenges') }}</a>
                <a class="hover:text-accent focus-visible:outline-2 focus-visible:outline-accent" href="#wallet">Google Wallet</a>
                <a class="hover:text-accent focus-visible:outline-2 focus-visible:outline-accent" href="#negocios">{{ __('landing.navigation.for_businesses') }}</a>
            </nav>
            <div class="flex items-center gap-3 text-sm">
                <a class="rounded-full px-3 py-2 text-zinc-200 hover:text-accent focus-visible:outline-2 focus-visible:outline-accent" href="{{ route('login') }}">{{ __('landing.actions.sign_in') }}</a>
                <a class="hidden rounded-full bg-accent px-5 py-2.5 font-bold text-zinc-950 transition hover:bg-lime-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent sm:inline-flex" href="{{ route('register') }}">{{ __('landing.actions.create_my_challenge') }}</a>
            </div>
            <details class="w-full rounded-xl border border-zinc-800 p-3 text-sm md:hidden">
                <summary class="cursor-pointer focus-visible:outline-2 focus-visible:outline-accent">{{ __('landing.navigation.menu') }}</summary>
                <nav class="flex flex-col gap-3 pt-4" aria-label="{{ __('landing.navigation.page_sections') }}">
                    <a href="#como-funciona">{{ __('landing.navigation.how_it_works') }}</a>
                    <a href="#retos">{{ __('landing.navigation.challenges') }}</a>
                    <a href="#wallet">Google Wallet</a>
                    <a href="#negocios">{{ __('landing.navigation.for_businesses') }}</a>
                </nav>
            </details>
        </div>
    </header>

    <main id="contenido">
        <section id="inicio" aria-labelledby="hero-title" class="relative isolate overflow-hidden border-b border-zinc-800 py-20 sm:py-28 lg:py-36">
            <div aria-hidden="true" class="pointer-events-none absolute -right-32 -top-64 -z-10 size-[38rem] rounded-full bg-accent/10 blur-3xl"></div>
            <div class="mx-auto grid max-w-7xl items-center gap-16 px-5 sm:px-8 lg:grid-cols-2">
                <div class="max-w-2xl">
                    <p class="mb-7 text-xs font-bold uppercase tracking-[.3em] text-accent">{{ __('landing.hero.eyebrow') }}</p>
                    <h1 id="hero-title" class="text-5xl font-semibold leading-[1.08] tracking-tight text-white sm:text-6xl lg:text-7xl">{{ __('landing.hero.heading') }}</h1>
                    <p class="mt-7 max-w-xl text-lg leading-relaxed text-zinc-300">{{ __('landing.hero.description') }}</p>
                    <div class="mt-9 flex flex-wrap gap-4">
                        <a class="rounded-full bg-accent px-7 py-4 font-bold text-zinc-950 transition hover:bg-lime-300 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent" href="{{ route('register') }}">{{ __('landing.actions.create_my_challenge') }}</a>
                        <a class="rounded-full border border-zinc-600 px-7 py-4 font-semibold text-white transition hover:border-accent hover:text-accent focus-visible:outline-2 focus-visible:outline-accent" href="#como-funciona">{{ __('landing.actions.see_how_it_works') }}</a>
                    </div>
                    <p class="mt-6 text-sm text-zinc-400">{{ __('landing.hero.registration_note') }}</p>
                </div>
                <div class="relative rounded-[2rem] border border-zinc-700 bg-zinc-900/90 p-5 shadow-2xl shadow-accent/5 sm:p-9" role="img" aria-label="{{ __('landing.hero.card_aria') }}">
                    <div aria-hidden="true" class="rounded-3xl border border-accent/30 bg-gradient-to-br from-zinc-800 via-zinc-900 to-zinc-950 p-7 sm:p-10">
                        <div class="flex justify-between text-sm font-bold tracking-wide text-accent">
                            <span>FidelitoPass</span>
                            <span>✳</span>
                        </div>
                        <p class="mt-16 text-xs uppercase tracking-[.2em] text-zinc-400">{{ __('landing.hero.card_caption') }}</p>
                        <p class="mt-4 text-4xl font-semibold text-white">{{ __('landing.hero.card_progress') }}</p>
                        <div class="mt-7 h-2 overflow-hidden rounded-full bg-zinc-700">
                            <div class="h-full w-3/5 rounded-full bg-accent"></div>
                        </div>
                        <p class="mt-7 text-sm text-zinc-300">{{ __('landing.hero.card_detail') }}</p>
                    </div>
                    <p aria-hidden="true" class="absolute -bottom-5 right-5 rounded-full border border-zinc-700 bg-zinc-800 px-5 py-3 text-sm font-semibold text-accent shadow-xl">{{ __('landing.hero.card_badge') }} ↗</p>
                </div>
            </div>
        </section>

        <section id="como-funciona" aria-labelledby="steps-title" class="scroll-mt-6 border-b border-zinc-800 bg-zinc-900/60 py-24">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <p class="text-xs font-bold uppercase tracking-[.3em] text-accent">{{ __('landing.steps.eyebrow') }}</p>
                <h2 id="steps-title" class="mt-4 max-w-2xl text-4xl font-semibold tracking-tight text-white sm:text-5xl">{{ __('landing.steps.heading') }}</h2>
                <p class="mt-5 max-w-2xl text-lg text-zinc-300">{{ __('landing.steps.description') }}</p>
                <ol class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        [__('landing.steps.create_heading'), __('landing.steps.create_detail')],
                        [__('landing.steps.share_heading'), __('landing.steps.share_detail')],
                        [__('landing.steps.validate_heading'), __('landing.steps.validate_detail')],
                        [__('landing.steps.reward_heading'), __('landing.steps.reward_detail')],
                    ] as [$heading, $detail])
                        <li class="rounded-3xl border border-zinc-700/80 bg-zinc-950 p-7 transition motion-safe:hover:-translate-y-1 hover:border-accent/50">
                            <span class="text-sm font-bold text-accent">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }} / 04</span>
                            <h3 class="mt-12 text-xl font-semibold text-white">{{ $heading }}</h3>
                            <p class="mt-4 leading-relaxed text-zinc-400">{{ $detail }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        <section id="retos" aria-labelledby="challenge-title" class="scroll-mt-6 border-b border-zinc-800 py-24">
            <div class="mx-auto grid max-w-7xl gap-12 px-5 sm:px-8 lg:grid-cols-2 lg:items-center">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.3em] text-accent">{{ __('landing.challenge.eyebrow') }}</p>
                    <h2 id="challenge-title" class="mt-4 text-4xl font-semibold tracking-tight text-white sm:text-5xl">{{ __('landing.challenge.heading') }}</h2>
                    <p class="mt-6 text-lg leading-relaxed text-zinc-300">{{ __('landing.challenge.description') }}</p>
                    <p class="mt-5 leading-relaxed text-zinc-400">{{ __('landing.challenge.detail') }}</p>
                </div>
                <div class="rounded-3xl border border-zinc-700 bg-zinc-900 p-8 sm:p-10">
                    <p class="text-xs font-bold uppercase tracking-[.3em] text-accent">{{ __('landing.challenge.example') }}</p>
                    <h3 class="mt-5 text-2xl font-semibold text-white">{{ __('landing.challenge.example_heading') }}</h3>
                    <ul class="mt-8 divide-y divide-zinc-700 text-zinc-300">
                        <li class="flex justify-between gap-4 py-5">
                            <span>{{ __('landing.challenge.regular_visit') }}</span>
                            <strong class="text-white">{{ __('landing.challenge.one_point') }}</strong>
                        </li>
                        <li class="flex justify-between gap-4 py-5">
                            <span>{{ __('landing.challenge.tuesday_visit') }}</span>
                            <strong class="text-accent">{{ __('landing.challenge.two_points') }}</strong>
                        </li>
                        <li class="flex justify-between gap-4 py-5">
                            <span>{{ __('landing.challenge.goal') }}</span>
                            <strong class="text-white">{{ __('landing.challenge.ten_points') }}</strong>
                        </li>
                    </ul>
                    <p class="mt-6 text-sm text-zinc-400">{{ __('landing.challenge.example_note') }}</p>
                </div>
            </div>
        </section>

        <section id="wallet" aria-labelledby="wallet-title" class="scroll-mt-6 border-b border-zinc-800 bg-zinc-900/60 py-24">
            <div class="mx-auto grid max-w-7xl gap-12 px-5 sm:px-8 lg:grid-cols-2 lg:items-center">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.3em] text-accent">{{ __('landing.wallet.eyebrow') }}</p>
                    <h2 id="wallet-title" class="mt-4 text-4xl font-semibold tracking-tight text-white sm:text-5xl">{{ __('landing.wallet.heading') }}</h2>
                    <p class="mt-6 text-lg leading-relaxed text-zinc-300">{{ __('landing.wallet.description') }}</p>
                </div>
                <div class="rounded-3xl border border-accent/30 bg-gradient-to-br from-zinc-800 to-zinc-950 p-9">
                    <p class="text-xs font-bold uppercase tracking-[.3em] text-accent">{{ __('landing.wallet.on_pass') }}</p>
                    <ul class="mt-8 grid gap-5 text-lg text-zinc-200">
                        <li>{{ __('landing.wallet.business_name') }}</li>
                        <li>{{ __('landing.wallet.points_and_goal') }}</li>
                        <li>{{ __('landing.wallet.reward_and_deadline') }}</li>
                    </ul>
                    <p class="mt-10 border-t border-zinc-700 pt-7 font-semibold text-white">{{ __('landing.wallet.closing') }}</p>
                </div>
            </div>
        </section>

        <section id="negocios" aria-labelledby="business-title" class="scroll-mt-6 relative overflow-hidden py-28">
            <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 bottom-0 h-64 bg-gradient-to-t from-accent/10 to-transparent"></div>
            <div class="relative mx-auto max-w-4xl px-5 text-center sm:px-8">
                <p class="text-xs font-bold uppercase tracking-[.3em] text-accent">{{ __('landing.navigation.for_businesses') }}</p>
                <h2 id="business-title" class="mt-5 text-4xl font-semibold tracking-tight text-white sm:text-6xl">{{ __('landing.business_cta.heading') }}</h2>
                <p class="mx-auto mt-7 max-w-2xl text-lg leading-relaxed text-zinc-300">{{ __('landing.business_cta.description') }}</p>
                <a class="mt-10 inline-flex rounded-full bg-accent px-8 py-4 font-bold text-zinc-950 transition hover:bg-lime-300 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent" href="{{ route('register') }}">{{ __('landing.actions.create_first_challenge') }}</a>
                <p class="mt-6 text-sm text-zinc-400">{{ __('landing.business_cta.registration_note') }}</p>
            </div>
        </section>
    </main>

    <footer class="border-t border-zinc-800 bg-zinc-950">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-6 px-5 py-10 text-sm text-zinc-400 sm:px-8">
            <span class="mr-auto font-bold text-white">Fidelito<span class="text-accent">Pass</span></span>
            <a class="hover:text-accent focus-visible:outline-2 focus-visible:outline-accent" href="{{ route('register') }}">{{ __('landing.actions.register') }}</a>
            <a class="hover:text-accent focus-visible:outline-2 focus-visible:outline-accent" href="{{ route('login') }}">{{ __('landing.actions.sign_in') }}</a>
            <a class="hover:text-accent focus-visible:outline-2 focus-visible:outline-accent" href="#inicio">{{ __('landing.navigation.back_to_top') }} ↑</a>
        </div>
    </footer>
</div>
