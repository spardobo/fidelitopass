<x-slot:description>
    {{ __('landing.page_description') }}
</x-slot:description>

<div id="page-top" class="landing-world min-h-screen text-[#F6F5F2]">
    <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-[#B7ABE4] focus:p-3 focus:text-[#181818]">
        {{ __('landing.navigation.skip_to_content') }}
    </a>

    <!-- header and navigation -->
    <header class="border-b border-white/10">
        <div class="mx-auto flex max-w-[1080px] items-center justify-between gap-4 px-6 py-6 md:px-8">
            <a href="#page-top" aria-label="{{ __('landing.navigation.back_to_top_label') }}"><img src="{{ asset('logo-header.webp') }}" alt="{{ __('landing.navigation.logo_alt') }}" class="h-auto w-40 sm:w-48" width="480" height="105"></a>

            <nav class="hidden gap-5 text-sm xl:flex" aria-label="{{ __('landing.navigation.page_sections') }}">
                <a href="#benefits" class="hover:text-[#D8CEF5]">
                    {{ __('landing.navigation.benefits') }}
                </a>
                <a href="#how-it-works" class="hover:text-[#D8CEF5]">
                    {{ __('landing.navigation.how_it_works') }}
                </a>
                <a href="#challenges" class="hover:text-[#D8CEF5]">
                    {{ __('landing.navigation.challenges') }}
                </a>
                <a href="#pass" class="hover:text-[#D8CEF5]">
                    {{ __('landing.navigation.pass') }}
                </a>
                <a href="#questions" class="hover:text-[#D8CEF5]">
                    {{ __('landing.faq.heading') }}
                </a>
                <a href="#business" class="hover:text-[#D8CEF5]">
                    {{ __('landing.navigation.start') }}
                </a>
            </nav>

            <details class="landing-menu relative xl:hidden">
                <summary class="cursor-pointer rounded-full border border-white/40 px-4 py-2 text-sm">
                    {{ __('landing.navigation.menu') }}
                </summary>
                <nav class="absolute right-0 top-full z-50 mt-3 flex w-56 flex-col gap-4 rounded-2xl bg-[#313131] p-6 shadow-xl" aria-label="{{ __('landing.navigation.page_sections') }}">
                    <a href="#benefits" class="hover:text-[#D8CEF5]">
                        {{ __('landing.navigation.benefits') }}
                    </a>
                    <a href="#how-it-works" class="hover:text-[#D8CEF5]">
                        {{ __('landing.navigation.how_it_works') }}
                    </a>
                    <a href="#challenges" class="hover:text-[#D8CEF5]">
                        {{ __('landing.navigation.challenges') }}
                    </a>
                    <a href="#pass" class="hover:text-[#D8CEF5]">
                        {{ __('landing.navigation.pass') }}
                    </a>
                    <a href="#questions" class="hover:text-[#D8CEF5]">
                        {{ __('landing.faq.heading') }}
                    </a>
                    <a href="#business" class="hover:text-[#D8CEF5]">
                        {{ __('landing.navigation.start') }}
                    </a>
                </nav>
            </details>
        </div>
    </header>

    <!-- main content -->
    <main id="content" tabindex="-1" class="mx-auto max-w-[1080px] px-6 pt-8 md:px-8 md:pt-10">
        <!-- hero and conceptual pass -->
        <section id="home" aria-labelledby="hero-title" class="grid items-center gap-12 rounded-3xl bg-[#303030] px-6 py-12 md:px-12 md:py-20 xl:grid-cols-[minmax(0,312px)_minmax(0,576px)] xl:gap-8">
            <div class="mx-auto flex w-full max-w-[576px] flex-col items-center gap-6 text-center xl:mx-0 xl:items-start xl:text-left">
                <span class="rounded-full border border-[#B7ABE4] px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[#D8CEF5]">
                    {{ __('landing.hero.eyebrow') }}
                </span>
                <h1 id="hero-title" class="max-w-[680px] text-4xl font-semibold tracking-tight md:text-5xl">
                    {{ __('landing.hero.heading') }}
                </h1>
                <p class="max-w-[680px] text-base text-[#C4C4C4] md:text-lg">
                    {{ __('landing.hero.description') }}
                </p>
                <a href="{{ route('register') }}" class="landing-button rounded-full bg-[#B7ABE4] px-5 py-3 font-semibold text-[#181818] transition-transform duration-300 hover:-translate-y-[3px] hover:bg-[#D8CEF5] hover:text-[#181818] active:scale-[.98]">
                    {{ __('landing.actions.create_account') }}
                </a>

                <p class="text-sm text-[#E0E0E0]">{{ __('landing.hero.sign_in_prompt') }} <a href="{{ route('login') }}" class="underline hover:text-[#D8CEF5]">{{ __('landing.hero.sign_in_link') }}</a></p>
            </div>

            <div class="mx-auto w-full max-w-[576px] xl:mx-0">
                <div class="landing-thumbnail" data-thumbnail>
                    <div class="landing-pass-scale">
                        <article aria-label="{{ __('landing.hero.pass_aria') }}" class="landing-pass flex flex-col gap-6 rounded-2xl bg-[#B7ABE4] p-6 text-[#181818] shadow-2xl" data-pass>
                            <div class="flex items-start justify-between gap-4">
                                <h2 class="text-xl font-bold">
                                    {{ __('landing.hero.sample.business') }}
                                </h2>
                                <img src="{{ asset('logo_icon.svg') }}" alt="" class="size-10 brightness-0" width="40" height="40">
                            </div>

                            <div class="grid grid-cols-[minmax(0,1fr)_144px] items-start gap-6 border-t border-[#181818]/25 pt-6">
                                <div class="flex flex-col gap-3">
                                    <h3 class="text-lg font-semibold">
                                        {{ __('landing.hero.sample.challenge_label') }}
                                    </h3>
                                    <p class="text-sm">
                                        {{ __('landing.hero.sample.challenge_description') }}
                                    </p>
                                    <p class="text-3xl font-bold">
                                        {{ __('landing.hero.sample.progress') }}
                                    </p>
                                    <p class="text-sm">
                                        {{ __('landing.hero.sample.visit_value') }}
                                    </p>
                                    <div class="border-t border-[#181818]/25 pt-4">
                                        <p class="font-semibold">
                                            {{ __('landing.hero.sample.reward') }}
                                        </p>
                                        <p class="text-sm">
                                            {{ __('landing.hero.sample.deadline') }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex flex-col items-center gap-3">
                                    <img src="{{ asset('preview-pass-qr.svg') }}" alt="{{ __('landing.hero.sample.qr_alt') }}" class="landing-pass-qr size-36 shrink-0 bg-white" width="232" height="232">
                                    <p class="text-center text-sm font-semibold">
                                        {{ __('landing.hero.sample.manual_code_label') }}
                                        <br>
                                        <span class="text-xl tracking-widest">
                                            {{ __('landing.hero.sample.manual_code') }}
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>

                <p class="mt-4 text-center text-sm text-[#E0E0E0] xl:text-left">
                    {{ __('landing.hero.demo_note') }}
                </p>
            </div>
        </section>

        <!-- benefits -->
        <section id="benefits" aria-labelledby="benefits-title" class="grid justify-items-center gap-8 py-20">
            <div class="max-w-[680px] text-center">
                <p class="mb-4 text-sm font-semibold text-[#D8CEF5]">
                    {{ __('landing.benefits.eyebrow') }}
                </p>
                <h2 id="benefits-title" class="text-3xl font-semibold md:text-4xl">
                    {{ __('landing.benefits.proposition_heading') }}
                </h2>
                <p class="mt-4 text-[#C4C4C4]">
                    {{ __('landing.benefits.problem') }}
                </p>
            </div>

            <div class="grid w-full gap-6 md:grid-cols-2">
                <article class="landing-info-card rounded-3xl bg-[#E8E8E8] p-8 text-[#181818] transition-[transform,box-shadow] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)]">
                    <img src="{{ asset('benefit-challenges.svg') }}" alt="" class="mb-6 size-16 brightness-0" width="64" height="64">
                    <h3 class="text-xl font-semibold">
                        {{ __('landing.benefits.challenge_heading') }}
                    </h3>
                    <p class="mt-3">
                        {{ __('landing.benefits.challenge_description') }}
                    </p>
                </article>

                <article class="landing-info-card rounded-3xl bg-[#313131] p-8 transition-[transform,box-shadow] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)]">
                    <img src="{{ asset('benefit-points.svg') }}" alt="" class="mb-6 size-16" width="64" height="64">
                    <h3 class="text-xl font-semibold">
                        {{ __('landing.benefits.progress_heading') }}
                    </h3>
                    <p class="mt-3 text-[#E0E0E0]">
                        {{ __('landing.benefits.progress_description') }}
                    </p>
                </article>
            </div>
        </section>

        <section aria-label="{{ __('landing.tagline.label') }}" class="py-16">
            <p class="mx-auto max-w-[680px] text-center text-4xl font-semibold md:text-5xl" data-tagline>@foreach (__('landing.tagline.words') as $word)<span class="tagline-word inline-block" data-tagline-word>{{ $word }}</span>{{ $loop->last ? '' : ' ' }}@endforeach</p>
        </section>

        <!-- how it works -->
        <section id="how-it-works" aria-labelledby="steps-title" class="py-20">
            <p class="text-sm font-semibold text-[#D8CEF5]">
                {{ __('landing.steps.eyebrow') }}
            </p>
            <h2 id="steps-title" class="mt-4 text-3xl font-semibold md:text-4xl">
                {{ __('landing.steps.heading') }}
            </h2>
            <p class="mt-4 max-w-[680px] text-[#C4C4C4]">
                {{ __('landing.steps.description') }}
            </p>
            <ol class="mt-8 grid gap-6 md:grid-cols-3">
                @foreach ([
                    [__('landing.steps.create_heading'), __('landing.steps.create_detail')],
                    [__('landing.steps.share_heading'), __('landing.steps.share_detail')],
                    [__('landing.steps.validate_heading'), __('landing.steps.validate_detail')],
                ] as [$heading, $detail])
                    <li class="landing-info-card rounded-3xl bg-[#313131] p-8 transition-[transform,box-shadow] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)]">
                        <span class="text-3xl text-[#D8CEF5]">
                            {{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}
                        </span>
                        <h3 class="mt-8 text-xl font-semibold">
                            {{ $heading }}
                        </h3>
                        <p class="mt-3 text-[#E0E0E0]">
                            {{ $detail }}
                        </p>
                    </li>
                @endforeach
            </ol>
        </section>

        <section id="challenges" aria-labelledby="challenge-title" class="rounded-3xl bg-[#303030] p-8 md:p-12">
            <p class="text-sm font-semibold text-[#D8CEF5]">
                {{ __('landing.challenge.eyebrow') }}
            </p>
            <h2 id="challenge-title" class="mt-4 text-3xl font-semibold md:text-4xl">
                {{ __('landing.challenge.heading') }}
            </h2>
            <p class="mt-4 max-w-[680px] text-[#E0E0E0]">
                {{ __('landing.challenge.description') }}
            </p>
            <p class="mt-4 max-w-[680px] text-[#E0E0E0]">
                {{ __('landing.challenge.detail') }}
            </p>
            <p class="mt-6 text-sm text-[#D8CEF5]">
                {{ __('landing.challenge.example_note') }}
            </p>
        </section>

        <section id="pass" aria-labelledby="wallet-title" class="py-20">
            <p class="text-sm font-semibold text-[#D8CEF5]">
                {{ __('landing.wallet.eyebrow') }}
            </p>
            <h2 id="wallet-title" class="mt-4 text-3xl font-semibold md:text-4xl">
                {{ __('landing.wallet.heading') }}
            </h2>
            <p class="mt-4 max-w-[680px] text-[#C4C4C4]">
                {{ __('landing.wallet.description') }}
            </p>
            <p class="mt-4 text-[#E0E0E0]">
                {{ __('landing.wallet.closing') }}
            </p>
        </section>

        <!-- frequently asked questions -->
        <section id="questions" aria-labelledby="faq-title" class="pb-20">
            <h2 id="faq-title" class="mb-8 text-3xl font-semibold md:text-4xl">
                {{ __('landing.faq.heading') }}
            </h2>
            <div class="grid gap-3">
                @foreach (__('landing.faq.items') as $item)
                    <details class="rounded-2xl bg-[#313131] p-6">
                        <summary class="cursor-pointer rounded-sm font-semibold transition-colors duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] hover:text-[#D8CEF5] focus-visible:text-[#D8CEF5]">
                            {{ $item['question'] }}
                        </summary>
                        <p class="mt-4 text-[#E0E0E0]">
                            {{ $item['answer'] }}
                        </p>
                    </details>
                @endforeach
            </div>
        </section>

        <!-- business call to action -->
        <section id="business" aria-labelledby="business-title" class="rounded-3xl bg-[#B7ABE4] p-8 text-[#181818] md:p-12">
            <h2 id="business-title" class="text-3xl font-semibold md:text-4xl">
                {{ __('landing.business_cta.heading') }}
            </h2>
            <p class="mt-4">
                {{ __('landing.business_cta.description') }}
            </p>
            <a href="{{ route('register') }}" class="landing-button mt-6 inline-flex rounded-full bg-[#181818] px-5 py-3 font-semibold text-white transition-transform duration-300 hover:-translate-y-[3px] hover:bg-[#303030] hover:text-white active:scale-[.98]">
                {{ __('landing.actions.create_account') }}
            </a>
            <p class="mt-4 text-sm">
                {{ __('landing.business_cta.registration_note') }}
            </p>
        </section>
    </main>

    <!-- footer -->
    <footer class="mx-auto flex max-w-[1080px] flex-wrap items-center gap-6 px-6 py-12 text-sm text-[#E0E0E0] md:px-8">
        <img src="{{ asset('logo-header.webp') }}" alt="{{ __('landing.navigation.logo_alt') }}" class="mr-auto w-32" width="480" height="105">

        <a href="{{ route('register') }}" class="hover:text-[#D8CEF5]">
            {{ __('landing.actions.register') }}
        </a>
        <a href="{{ route('login') }}" class="hover:text-[#D8CEF5]">
            {{ __('landing.actions.sign_in') }}
        </a>
        <a href="#page-top" class="hover:text-[#D8CEF5]">
            {{ __('landing.navigation.back_to_top') }}
        </a>
    </footer>
</div>

@script
<script>
    const landingRoot = $wire.$el;

    if (!landingRoot.dataset.landingReady) {
        landingRoot.dataset.landingReady = 'true';

        landingRoot.querySelectorAll('.landing-menu nav a').forEach(link => {
            link.addEventListener('click', () => {
                link.closest('details').open = false;
            });
        });

        // pass sizing and pointer animation
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const thumbnail = landingRoot.querySelector('[data-thumbnail]');
        const pass = landingRoot.querySelector('[data-pass]');
        const passWidth = 576;
        let animationFrame = 0;

        const resizeObserver = new ResizeObserver(() => {
            thumbnail.style.setProperty('--landing-scale', Math.min(1, thumbnail.clientWidth / passWidth));
        });
        resizeObserver.observe(thumbnail);

        function resetPass() {
            cancelAnimationFrame(animationFrame);
            animationFrame = 0;
            pass.style.removeProperty('transform');
            pass.style.removeProperty('--sheen-x');
            pass.style.removeProperty('--sheen-y');
        }

        function movePass(event) {
            if (event.pointerType !== 'mouse' || reducedMotion.matches) {
                resetPass();
                return;
            }

            const bounds = thumbnail.getBoundingClientRect();
            const horizontalPosition = Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width));
            const verticalPosition = Math.max(0, Math.min(1, (event.clientY - bounds.top) / bounds.height));

            cancelAnimationFrame(animationFrame);
            animationFrame = requestAnimationFrame(() => {
                pass.style.setProperty('--sheen-x', `${10 + 80 * horizontalPosition}%`);
                pass.style.setProperty('--sheen-y', `${10 + 80 * verticalPosition}%`);

                const angle = Math.abs(2 * horizontalPosition - 1) < .08 ? 0 : 4.5 * (2 * horizontalPosition - 1);
                pass.style.transform = angle
                    ? `perspective(900px) rotate3d(-576, 384, 0, ${angle}deg)`
                    : 'none';
            });
        }

        // interaction listeners
        pass.addEventListener('pointermove', movePass);
        pass.addEventListener('pointerleave', resetPass);
        reducedMotion.addEventListener('change', () => {
            if (reducedMotion.matches) {
                resetPass();
            }
        });

        // tagline reveal
        if (!reducedMotion.matches && 'IntersectionObserver' in window) {
            const words = Array.from(landingRoot.querySelectorAll('[data-tagline-word]'));
            const tagline = landingRoot.querySelector('[data-tagline]');
            const observer = new IntersectionObserver(entries => {
                if (!entries[0].isIntersecting) {
                    return;
                }

                observer.disconnect();
                words.forEach((word, index) => {
                    window.setTimeout(() => word.classList.add('is-lit'), index * 180);
                });
            });

            observer.observe(tagline);
        }
    }
</script>
@endscript
