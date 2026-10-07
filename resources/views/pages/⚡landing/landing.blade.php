<x-slot:description>
    {{ __('landing.page_description') }}
</x-slot:description>

<div
    id="page-top"
    class="landing-world group/landing min-h-screen overflow-x-clip bg-app-canvas font-sans text-app-ink [--app-focus-offset:4px]
        [&_h1]:text-balance [&_h2]:text-balance [&_h3]:text-balance [&_p]:text-pretty [&_[data-flux-text]]:text-inherit [&_section[id]]:scroll-mt-8
        [html:has(&)]:scroll-smooth motion-reduce:[html:has(&)]:scroll-auto
        motion-reduce:[&_*]:animate-none! motion-reduce:[&_*]:[transition:none]!
        motion-reduce:[&_*::before]:animate-none! motion-reduce:[&_*::before]:[transition:none]!
        motion-reduce:[&_*::after]:animate-none! motion-reduce:[&_*::after]:[transition:none]!"
>
    <flux:link href="#content" :accent="false" variant="ghost" class="landing-link sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-app-accent! focus:p-3 focus:text-app-on-accent!">
        {{ __('landing.navigation.skip_to_content') }}
    </flux:link>

    <!-- header and navigation -->
    <header class="border-b border-white/10 bg-app-canvas">
        <div class="mx-auto flex max-w-[68rem] items-center justify-between gap-4 px-6 py-6 md:px-8">
            <flux:link href="#page-top" :accent="false" variant="ghost" class="landing-link" aria-label="{{ __('landing.navigation.back_to_top_label') }}">
                <img src="{{ asset('logo-header.webp') }}" alt="{{ __('landing.navigation.logo_alt') }}" class="h-auto w-40 sm:w-48" width="480" height="105">
            </flux:link>

            <nav class="hidden gap-5 text-sm xl:flex" aria-label="{{ __('landing.navigation.page_sections') }}">
                <flux:link href="#benefits" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                    {{ __('landing.navigation.benefits') }}
                </flux:link>
                <flux:link href="#how-it-works" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                    {{ __('landing.navigation.how_it_works') }}
                </flux:link>
                <flux:link href="#challenges" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                    {{ __('landing.navigation.challenges') }}
                </flux:link>
                <flux:link href="#pass" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                    {{ __('landing.navigation.pass') }}
                </flux:link>
                <flux:link href="#questions" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                    {{ __('landing.faq.heading') }}
                </flux:link>
                <flux:link href="#business" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                    {{ __('landing.navigation.start') }}
                </flux:link>
            </nav>

            <details class="landing-menu relative xl:hidden">
                <summary class="app-focus cursor-pointer rounded-full border border-white/40 px-4 py-2 text-sm">
                    {{ __('landing.navigation.menu') }}
                </summary>
                <nav class="absolute right-0 top-full z-50 mt-3 flex w-56 flex-col gap-4 rounded-2xl bg-app-surface p-6 shadow-xl" aria-label="{{ __('landing.navigation.page_sections') }}">
                    <flux:link href="#benefits" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                        {{ __('landing.navigation.benefits') }}
                    </flux:link>
                    <flux:link href="#how-it-works" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                        {{ __('landing.navigation.how_it_works') }}
                    </flux:link>
                    <flux:link href="#challenges" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                        {{ __('landing.navigation.challenges') }}
                    </flux:link>
                    <flux:link href="#pass" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                        {{ __('landing.navigation.pass') }}
                    </flux:link>
                    <flux:link href="#questions" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                        {{ __('landing.faq.heading') }}
                    </flux:link>
                    <flux:link href="#business" :accent="false" variant="ghost" class="landing-link hover:text-[#D8CEF5]">
                        {{ __('landing.navigation.start') }}
                    </flux:link>
                </nav>
            </details>
        </div>
    </header>

    <!-- main content -->
    <main
        id="content"
        tabindex="-1"
        class="mx-auto flow-root max-w-[68rem] px-6 pt-[var(--landing-main-base-gap)] pb-[var(--landing-closing-gap,var(--landing-main-base-gap))] [--landing-main-base-gap:2rem] md:px-8 md:[--landing-main-base-gap:2.5rem]
            motion-safe:group-data-reveal-ready/landing:[&>section[data-reveal=pending]]:opacity-0
            motion-safe:group-data-reveal-ready/landing:[&>section[data-reveal=pending]]:[transform:translateY(1.5rem)]
            motion-safe:[&>section[data-reveal=shown]]:opacity-100 motion-safe:[&>section[data-reveal=shown]]:transform-none
            motion-safe:[&>section[data-reveal=shown]]:[transition:opacity_500ms_ease-out,transform_500ms_ease-out]"
    >
        <!-- hero and conceptual pass -->
        <section
            id="home"
            aria-labelledby="hero-title"
            class="grid grid-cols-[minmax(0,1fr)] items-center gap-12 rounded-3xl border border-app-border bg-app-surface px-6 py-12 md:px-12 md:py-20
                mt-[var(--landing-hero-extra-gap,0px)] mb-[calc(var(--landing-hero-base-gap,var(--landing-main-base-gap))+var(--landing-hero-extra-gap,0px))]
                group-data-hero-measured/landing:min-h-[min(44rem,max(0px,calc(100svh-var(--landing-header-bottom)-2*var(--landing-hero-base-gap,var(--landing-main-base-gap)))))]
                [&>div]:min-w-0 [&_h1]:min-w-0 [&_h1]:max-w-full [&_h1]:wrap-anywhere [&_p]:min-w-0 [&_p]:max-w-full [&_p]:wrap-anywhere
                xl:grid-cols-[minmax(0,.88fr)_minmax(0,1.3fr)] xl:gap-x-12 xl:p-12"
        >
            <div class="mx-auto flex w-full max-w-[576px] flex-col items-center gap-6 text-center xl:mx-0 xl:items-start xl:text-left">
                <span class="rounded-full border border-app-accent px-3 py-2 text-xs font-semibold uppercase tracking-wider text-app-accent">
                    {{ __('landing.hero.eyebrow') }}
                </span>
                <flux:heading level="1" id="hero-title" class="app-role-marketing-title max-w-[680px] tracking-tight">
                    {{ __('landing.hero.heading') }}
                </flux:heading>
                <flux:text size="lg" class="app-role-body max-w-[680px] text-[#C4C4C4]!">
                    {{ __('landing.hero.description') }}
                </flux:text>
                <flux:button variant="primary" href="{{ route('register') }}" class="landing-button app-role-action! app-primary-colors app-focus landing-button-primary min-h-app-control h-auto min-w-0 max-w-full wrap-anywhere whitespace-normal rounded-full transition-transform duration-300 hover:-translate-y-[3px] active:scale-[.98]">
                    {{ __('landing.actions.create_account') }}
                </flux:button>

                    <flux:text class="app-role-support text-[#E0E0E0]!">
                    {{ __('landing.hero.sign_in_prompt') }} <flux:link href="{{ route('login') }}" :accent="false" class="landing-link text-app-accent! underline! hover:underline!">
                        {{ __('landing.hero.sign_in_link') }}
                    </flux:link>
                </flux:text>
            </div>

            <div class="mx-auto w-full max-w-[576px] xl:mx-0">
                <div data-thumbnail class="landing-thumbnail relative aspect-[3/2] w-full max-w-[576px] @container/pass-preview">
                    <div class="absolute top-0 left-0 origin-top-left [transform:scale(var(--landing-scale,1))]">
                        <x-pass-preview
                            :business-name="__('landing.hero.sample.business')"
                            :aria-label="__('landing.hero.pass_aria')"
                            :marketing="true"
                            data-pass
                            class="landing-pass isolate origin-center rounded-2xl bg-app-accent text-app-on-accent shadow-2xl
                                bg-[linear-gradient(115deg,transparent_12%,rgb(255_255_255/24%)_34%,rgb(255_255_255/8%)_50%,transparent_72%)]
                                bg-size-[160%_160%] bg-position-[var(--sheen-x,50%)_var(--sheen-y,50%)]
                                motion-safe:[transition:transform_180ms_cubic-bezier(0.32,0.72,0,1),background-position_180ms_cubic-bezier(0.32,0.72,0,1)]"
                        >
                            <x-slot:promotionLabel>{{ __('landing.hero.sample.challenge_label') }}</x-slot:promotionLabel>
                            <x-slot:promotionDescription>{{ __('landing.hero.sample.challenge_description') }}</x-slot:promotionDescription>
                            <x-slot:progress>{{ __('landing.hero.sample.progress') }}</x-slot:progress>
                            <x-slot:extraPoints>{{ __('landing.hero.sample.visit_value') }}</x-slot:extraPoints>
                            <x-slot:reward>{{ __('landing.hero.sample.reward') }}</x-slot:reward>
                            <x-slot:deadline>{{ __('landing.hero.sample.deadline') }}</x-slot:deadline>

                            <x-slot:qr>
                                <img src="{{ asset('preview-pass-qr.svg') }}" alt="{{ __('landing.hero.sample.qr_alt') }}" class="landing-pass-qr relative z-1 size-32 shrink-0 bg-white" width="232" height="232">
                            </x-slot:qr>
                            <x-slot:manualCode>
                                <flux:text class="font-semibold!">
                                    {{ __('landing.hero.sample.manual_code_label') }}
                                    <br>
                                    <span class="text-xl tracking-widest">
                                        {{ __('landing.hero.sample.manual_code') }}
                                    </span>
                                </flux:text>
                            </x-slot:manualCode>
                        </x-pass-preview>
                    </div>
                </div>

            </div>
        </section>

        <!-- benefits -->
        <section id="benefits" aria-labelledby="benefits-title" class="grid justify-items-center gap-8 py-20">
            <div class="max-w-[680px] text-center">
                <flux:text class="mb-4 font-semibold! text-app-accent!">
                    {{ __('landing.benefits.eyebrow') }}
                </flux:text>
                <flux:heading level="2" id="benefits-title" class="app-role-marketing-section text-app-ink!">
                    {{ __('landing.benefits.proposition_heading') }}
                </flux:heading>
                <flux:text size="lg" class="mt-4 text-[#C4C4C4]!">
                    {{ __('landing.benefits.problem') }}
                </flux:text>
            </div>

            <div class="grid w-full gap-6 md:grid-cols-2">
                <article class="landing-info-card rounded-3xl border-app-priority-border bg-app-emphasis p-8 text-app-ink">
                    <flux:icon.arrow-path-rounded-square variant="outline" aria-hidden="true" class="mb-6 size-16 text-app-accent" />
                    <flux:heading level="3" class="app-role-marketing-card-title!">
                        {{ __('landing.benefits.challenge_heading') }}
                    </flux:heading>
                    <flux:text size="lg" class="mt-3 text-app-ink-priority!">
                        {{ __('landing.benefits.challenge_description') }}
                    </flux:text>
                </article>

                <article class="landing-info-card rounded-3xl bg-app-surface p-8">
                    <flux:icon.arrow-trending-up variant="outline" aria-hidden="true" class="mb-6 size-16 text-app-accent" />
                    <flux:heading level="3" class="app-role-marketing-card-title!">
                        {{ __('landing.benefits.progress_heading') }}
                    </flux:heading>
                    <flux:text size="lg" class="mt-3 text-[#E0E0E0]!">
                        {{ __('landing.benefits.progress_description') }}
                    </flux:text>
                </article>
            </div>
        </section>

        <!-- editorial statement: locale-owned groups consume the unchanged word sequence -->
        <section aria-label="{{ __('landing.tagline.label') }}" class="py-16">
            @php($taglineWords = collect(__('landing.tagline.words')))
            <flux:text
                data-tagline
                class="app-role-marketing-statement-title! mx-auto max-w-3xl text-center tracking-tight text-balance!"
            >
                @foreach (__('landing.tagline.presentation_groups') as $group)
                    <span @class([
                        '[--landing-word-ink:var(--color-app-ink)]' => $group['role'] === 'primary',
                        '[--landing-word-ink:var(--color-app-accent)]' => $group['role'] === 'reward',
                        '[--landing-word-ink:var(--color-app-ink-help)]' => $group['role'] === 'secondary',
                    ])>
                        @foreach ($taglineWords->splice(0, $group['word_count']) as $word)
                            <span
                                data-tagline-word
                                class="tagline-word inline-block text-[var(--landing-word-ink,var(--color-app-ink))] transition-colors duration-700 ease-[cubic-bezier(0.32,0.72,0,1)]
                                    motion-safe:group-data-landing-ready/landing:[&:not(.is-lit)]:text-[#8C8C8C]"
                            >{{ $word }}</span>{{ $loop->last ? '' : ' ' }}
                        @endforeach
                    </span>{{ $loop->last ? '' : ' ' }}
                @endforeach
            </flux:text>
        </section>

        <!-- how it works -->
        <section id="how-it-works" aria-labelledby="steps-title" class="py-20">
            <flux:text class="font-semibold! text-app-accent!">
                {{ __('landing.steps.eyebrow') }}
            </flux:text>
            <flux:heading level="2" id="steps-title" class="app-role-marketing-section mt-4 text-inherit!">
                {{ __('landing.steps.heading') }}
            </flux:heading>
            <flux:text size="lg" class="mt-4 max-w-[680px] text-[#C4C4C4]!">
                {{ __('landing.steps.description') }}
            </flux:text>
            <ol class="mt-8 grid gap-6 md:grid-cols-3">
                @foreach ([
                    [__('landing.steps.create_heading'), __('landing.steps.create_detail')],
                    [__('landing.steps.share_heading'), __('landing.steps.share_detail')],
                    [__('landing.steps.validate_heading'), __('landing.steps.validate_detail')],
                ] as [$heading, $detail])
                    <li class="landing-info-card rounded-3xl bg-app-surface p-8">
                        <span class="text-3xl text-app-accent!">
                            {{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}
                        </span>
                        <flux:heading level="3" class="app-role-marketing-card-title! mt-8">
                            {{ $heading }}
                        </flux:heading>
                        <flux:text size="lg" class="mt-3 text-[#E0E0E0]!">
                            {{ $detail }}
                        </flux:text>
                    </li>
                @endforeach
            </ol>
        </section>

        <section id="challenges" aria-labelledby="challenge-title" class="rounded-3xl border border-app-priority-border bg-app-emphasis p-8 text-app-ink md:p-12">
            <flux:text class="app-role-marketing-eyebrow">
                {{ __('landing.challenge.eyebrow') }}
            </flux:text>
            <flux:heading level="2" id="challenge-title" class="app-role-marketing-section mt-4 text-inherit!">
                {{ __('landing.challenge.heading') }}
            </flux:heading>
            <flux:text size="lg" class="mt-4 max-w-[680px] text-app-ink-priority!">
                {{ __('landing.challenge.description') }}
            </flux:text>
            <flux:text size="lg" class="mt-4 max-w-[680px] text-app-ink-priority!">
                {{ __('landing.challenge.detail') }}
            </flux:text>
            <flux:text class="mt-6 border-t border-app-priority-border pt-5 text-app-ink-priority!">
                {{ __('landing.challenge.example_note') }}
            </flux:text>
        </section>

        <section id="pass" aria-labelledby="wallet-title" class="py-20">
            <flux:text class="font-semibold! text-app-accent!">
                {{ __('landing.wallet.eyebrow') }}
            </flux:text>
            <flux:heading level="2" id="wallet-title" class="app-role-marketing-section mt-4 text-inherit!">
                {{ __('landing.wallet.heading') }}
            </flux:heading>
            <flux:text size="lg" class="mt-4 max-w-[680px] text-[#C4C4C4]!">
                {{ __('landing.wallet.description') }}
            </flux:text>
            <flux:text size="lg" class="mt-4 text-[#E0E0E0]!">
                {{ __('landing.wallet.closing') }}
            </flux:text>
        </section>

        <!-- frequently asked questions -->
        <section id="questions" aria-labelledby="faq-title" class="pb-20">
            <flux:heading level="2" id="faq-title" class="app-role-marketing-section mb-8 text-inherit!">
                {{ __('landing.faq.heading') }}
            </flux:heading>
            <div class="grid gap-3">
                @foreach (__('landing.faq.items') as $item)
                    <details class="group/faq rounded-2xl border border-app-border bg-app-surface p-6 open:border-app-priority-border">
                        <summary class="app-focus flex cursor-pointer list-none items-center justify-between gap-4 rounded-sm font-semibold transition-colors duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] hover:text-app-accent-text focus-visible:text-[#D8CEF5] group-open/faq:text-app-accent-text [&::-webkit-details-marker]:hidden">
                            <span>
                                {{ $item['question'] }}
                            </span>
                            <flux:icon.chevron-down variant="outline" class="size-5 text-app-ink-help transition-transform duration-160 ease-[ease] group-open/faq:[transform:rotate(180deg)] group-open/faq:text-app-accent-text" />
                        </summary>
                        <flux:text size="lg" class="mt-4 border-t border-app-line pt-4 text-[#E0E0E0]!">
                            {{ $item['answer'] }}
                        </flux:text>
                    </details>
                @endforeach
            </div>
        </section>

        <!-- business call to action -->
        <section id="business" aria-labelledby="business-title" class="rounded-3xl bg-app-accent p-8 text-app-on-accent md:p-12">
            <flux:heading level="2" id="business-title" class="app-role-marketing-section text-inherit!">
                {{ __('landing.business_cta.heading') }}
            </flux:heading>
            <flux:text size="lg" class="mt-4">
                {{ __('landing.business_cta.description') }}
            </flux:text>
            <flux:button variant="primary" href="{{ route('register') }}" class="landing-button app-role-action! app-focus mt-6 min-h-app-control h-auto whitespace-normal rounded-full bg-app-on-accent! text-white! transition-transform duration-300 hover:-translate-y-[3px] hover:bg-[#303030]! active:scale-[.98]">
                {{ __('landing.actions.create_account') }}
            </flux:button>
        </section>
    </main>

    <!-- footer -->
    <footer class="mx-auto grid max-w-[68rem] grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 border-t border-app-line px-6 py-6 text-sm text-[#E0E0E0] sm:flex sm:flex-nowrap sm:gap-6 md:px-8">
        <img src="{{ asset('logo-header.webp') }}" alt="{{ __('landing.navigation.logo_alt') }}" class="col-start-1 row-start-1 mr-auto w-32" width="480" height="105">

        <flux:link href="{{ route('register') }}" :accent="false" variant="ghost" class="landing-link col-start-1 row-start-2 inline-flex min-h-11 items-center hover:text-[#D8CEF5]">
            {{ __('landing.actions.register') }}
        </flux:link>
        <flux:link href="{{ route('login') }}" :accent="false" variant="ghost" class="landing-link col-start-2 row-start-2 inline-flex min-h-11 w-full items-center justify-end text-right hover:text-[#D8CEF5] sm:w-auto sm:justify-start sm:text-left">
            {{ __('landing.actions.sign_in') }}
        </flux:link>
        <flux:link href="#page-top" :accent="false" variant="ghost" class="landing-link col-start-2 row-start-1 inline-flex min-h-11 w-full items-center justify-end whitespace-nowrap text-right hover:text-[#D8CEF5] sm:w-auto sm:justify-start sm:text-left">
            {{ __('landing.navigation.back_to_top') }}
        </flux:link>
    </footer>
</div>

@script
<script>
    (function initializeLanding() {
        // ---------------------------------------------------------------------
        // root ownership
        // ---------------------------------------------------------------------
        const landingRoot = $wire.$el;

        if (!landingRoot.isConnected || landingRoot.landingOwner) return;

        const owner = {};
        landingRoot.landingOwner = owner;

        // ---------------------------------------------------------------------
        // configuration
        // ---------------------------------------------------------------------
        const passWidthPx = 576;
        const passHeightPx = 384;
        const maximumTiltDegrees = 8;
        const tiltDeadZone = .08;
        const perspectivePx = 900;
        const taglineDelayMs = 180;
        const sectionThreshold = .08;

        // ---------------------------------------------------------------------
        // references
        // ---------------------------------------------------------------------
        const main = landingRoot.querySelector('main');
        const header = landingRoot.querySelector('header');
        const hero = landingRoot.querySelector('#home');
        const benefits = landingRoot.querySelector('#benefits');
        const thumbnail = landingRoot.querySelector('[data-thumbnail]');
        const pass = landingRoot.querySelector('[data-pass]');
        const tagline = landingRoot.querySelector('[data-tagline]');
        const words = Array.from(landingRoot.querySelectorAll('[data-tagline-word]'));
        const sections = Array.from(main.querySelectorAll(':scope > section:not(#home)'));
        const menuLinks = Array.from(landingRoot.querySelectorAll('.landing-menu nav a'));
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

        // ---------------------------------------------------------------------
        // owned state
        // ---------------------------------------------------------------------
        const listeners = new AbortController();
        const observers = new Set();
        const timers = new Set();
        const frames = new Set();
        let destroyed = false;
        let passFrame = null;
        let sectionObserver = null;
        let taglineObserver = null;
        let taglineStarted = false;

        // ---------------------------------------------------------------------
        // utilities
        // ---------------------------------------------------------------------
        function referencesCurrent() {
            // a connected root can still contain replaced children; pending work must not use stale nodes.
            return landingRoot.querySelector('main') === main
                && landingRoot.querySelector('header') === header
                && landingRoot.querySelector('#home') === hero
                && landingRoot.querySelector('#benefits') === benefits
                && landingRoot.querySelector('[data-thumbnail]') === thumbnail
                && landingRoot.querySelector('[data-pass]') === pass
                && landingRoot.querySelector('[data-tagline]') === tagline
                && sections.every(section => section.parentElement === main)
                && words.every(word => tagline.contains(word))
                && menuLinks.every(link => landingRoot.contains(link));
        }

        function isActive() {
            return !destroyed && landingRoot.isConnected
                && landingRoot.landingOwner === owner && referencesCurrent();
        }

        function scheduleFrame(callback) {
            const frame = requestAnimationFrame(() => {
                frames.delete(frame);

                if (isActive()) callback();
            });

            frames.add(frame);

            return frame;
        }

        function scheduleWord(word, delayMs) {
            const timer = window.setTimeout(() => {
                timers.delete(timer);

                if (isActive() && !reducedMotion.matches) word.classList.add('is-lit');
            }, delayMs);

            timers.add(timer);
        }

        function disconnectObserver(observer) {
            if (!observer || !observers.has(observer)) return;

            observer.disconnect();
            observers.delete(observer);
        }

        function setLayoutProperty(element, property, value) {
            if (element.style.getPropertyValue(property) !== value) {
                element.style.setProperty(property, value);
            }
        }

        // ---------------------------------------------------------------------
        // navigation
        // ---------------------------------------------------------------------
        function closeMenu(event) {
            if (!isActive()) return;

            event.currentTarget.closest('details').open = false;
        }

        function initializeNavigation() {
            menuLinks.forEach(link => link.addEventListener('click', closeMenu, { signal: listeners.signal }));
            landingRoot.addEventListener('click', revealAnchorTarget, { signal: listeners.signal });
            window.addEventListener('hashchange', revealHashTarget, { signal: listeners.signal });
        }

        // ---------------------------------------------------------------------
        // hero layout
        // ---------------------------------------------------------------------
        function measureLanding() {
            if (!isActive()) return;

            setLayoutProperty(thumbnail, '--landing-scale', String(Math.min(1, thumbnail.clientWidth / passWidthPx)));

            // measure the stable header boundary, never the hero's own distributed margin.
            const headerBottomPx = header.getBoundingClientRect().bottom + window.scrollY;
            const baseGapPx = parseFloat(getComputedStyle(main).paddingTop) || 0;
            setLayoutProperty(hero, '--landing-header-bottom', `${headerBottomPx}px`);
            setLayoutProperty(hero, '--landing-hero-base-gap', `${baseGapPx}px`);
            landingRoot.dataset.heroMeasured = 'true';

            const availableHeightPx = window.innerHeight - headerBottomPx - 2 * baseGapPx;
            const sectionSpacePx = parseFloat(getComputedStyle(benefits).paddingTop) || baseGapPx;
            const boundedExtraGapPx = Math.max(0, Math.min(
                (availableHeightPx - hero.getBoundingClientRect().height) / 2,
                sectionSpacePx - baseGapPx,
            ));
            const extraGapPx = Math.floor(boundedExtraGapPx / 4) * 4;
            setLayoutProperty(hero, '--landing-hero-extra-gap', `${extraGapPx}px`);

            // the page closes with its opening gap without reducing hero availability.
            setLayoutProperty(main, '--landing-closing-gap', `${baseGapPx + extraGapPx}px`);
        }

        function initializeHeroLayout() {
            measureLanding();

            if (typeof ResizeObserver === 'function') {
                const observer = new ResizeObserver(measureLanding);
                observers.add(observer);
                [thumbnail, header, main].forEach(element => observer.observe(element));
            }

            window.addEventListener('resize', measureLanding, { signal: listeners.signal });
            document.fonts?.ready.then(measureLanding);
        }

        // ---------------------------------------------------------------------
        // pass interaction
        // ---------------------------------------------------------------------
        function cancelPassFrame() {
            if (passFrame === null) return;

            cancelAnimationFrame(passFrame);
            frames.delete(passFrame);
            passFrame = null;
        }

        function resetPass() {
            cancelPassFrame();
            pass.style.removeProperty('transform');
            pass.style.removeProperty('--sheen-x');
            pass.style.removeProperty('--sheen-y');
        }

        function calculatePassPresentation(event) {
            const bounds = thumbnail.getBoundingClientRect();
            const horizontalPosition = Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width));
            const verticalPosition = Math.max(0, Math.min(1, (event.clientY - bounds.top) / bounds.height));
            const displacement = 2 * horizontalPosition - 1;
            const angleDegrees = Math.abs(displacement) < tiltDeadZone ? 0 : maximumTiltDegrees * displacement;

            return { horizontalPosition, verticalPosition, angleDegrees };
        }

        function renderPassPresentation({ horizontalPosition, verticalPosition, angleDegrees }) {
            pass.style.setProperty('--sheen-x', `${10 + 80 * horizontalPosition}%`);
            pass.style.setProperty('--sheen-y', `${10 + 80 * verticalPosition}%`);
            pass.style.transform = angleDegrees
                ? `perspective(${perspectivePx}px) rotate3d(-${passWidthPx}, ${passHeightPx}, 0, ${angleDegrees}deg)`
                : 'none';
        }

        function movePass(event) {
            if (!isActive()) return;

            if (event.pointerType !== 'mouse' || reducedMotion.matches) {
                resetPass();
                return;
            }

            cancelPassFrame();

            // capture pointer geometry now; only presentation writes wait for the owned frame.
            const presentation = calculatePassPresentation(event);
            passFrame = scheduleFrame(() => {
                passFrame = null;
                if (reducedMotion.matches) return;

                renderPassPresentation(presentation);
            });
        }

        function initializePassInteraction() {
            resetPass();
            pass.addEventListener('pointermove', movePass, { signal: listeners.signal });
            pass.addEventListener('pointerleave', resetPass, { signal: listeners.signal });
        }

        // ---------------------------------------------------------------------
        // section reveal
        // ---------------------------------------------------------------------
        function revealSection(section) {
            if (!sections.includes(section)) return;

            section.dataset.reveal = 'shown';
            sectionObserver?.unobserve(section);

            if (sections.every(element => element.dataset.reveal === 'shown')) {
                disconnectObserver(sectionObserver);
            }
        }

        function revealFocusedSection(event) {
            if (!isActive()) return;

            revealSection(event.currentTarget);
        }

        function revealFragment(fragment) {
            if (!fragment.startsWith('#')) return;

            const target = Array.from(landingRoot.querySelectorAll('[id]')).find(element => `#${element.id}` === fragment);
            if (!target) return;

            revealSection(target.closest('section'));
        }

        function revealAnchorTarget(event) {
            if (!isActive()) return;

            const anchor = event.target.closest('a[href^="#"]');
            if (anchor) revealFragment(anchor.getAttribute('href'));
        }

        function revealHashTarget() {
            if (isActive()) revealFragment(window.location.hash);
        }

        function onSectionIntersection(entries) {
            if (!isActive() || reducedMotion.matches) return;

            entries.forEach(entry => {
                if (entry.isIntersecting) revealSection(entry.target);
            });
        }

        function observePendingSections() {
            if (reducedMotion.matches) return;

            sections.forEach(section => {
                if (section.dataset.reveal === 'pending') sectionObserver.observe(section);
            });
        }

        function showAllSections() {
            sections.forEach(revealSection);
            disconnectObserver(sectionObserver);
            delete landingRoot.dataset.revealReady;
        }

        function initializeSectionReveal() {
            if (reducedMotion.matches || typeof IntersectionObserver !== 'function') return;

            sectionObserver = new IntersectionObserver(onSectionIntersection, { threshold: sectionThreshold });
            observers.add(sectionObserver);
            sections.forEach(section => {
                if (section.dataset.reveal !== 'shown') section.dataset.reveal = 'pending';
                section.addEventListener('focusin', revealFocusedSection, { signal: listeners.signal });
            });

            landingRoot.dataset.revealReady = 'true';
            revealHashTarget();

            // two owned frames let initially visible sections paint their entry state.
            scheduleFrame(() => scheduleFrame(observePendingSections));
        }

        // ---------------------------------------------------------------------
        // tagline reveal
        // ---------------------------------------------------------------------
        function completeTagline() {
            timers.forEach(timer => window.clearTimeout(timer));
            timers.clear();
            disconnectObserver(taglineObserver);
            words.forEach(word => word.classList.add('is-lit'));
        }

        function onTaglineIntersection(entries) {
            if (!isActive() || reducedMotion.matches || taglineStarted) return;
            if (!entries.some(entry => entry.isIntersecting)) return;

            taglineStarted = true;
            disconnectObserver(taglineObserver);
            words.forEach((word, index) => scheduleWord(word, index * taglineDelayMs));
        }

        function initializeTaglineReveal() {
            if (reducedMotion.matches || typeof IntersectionObserver !== 'function') {
                completeTagline();
                return;
            }
            if (words.every(word => word.classList.contains('is-lit'))) return;

            taglineObserver = new IntersectionObserver(onTaglineIntersection);
            observers.add(taglineObserver);
            taglineObserver.observe(tagline);
        }

        // ---------------------------------------------------------------------
        // lifecycle
        // ---------------------------------------------------------------------
        function onMotionChange() {
            if (!isActive() || !reducedMotion.matches) return;
            resetPass();
            frames.forEach(frame => cancelAnimationFrame(frame));
            frames.clear();
            showAllSections();
            completeTagline();
        }

        function onRootMutation() {
            if (!landingRoot.isConnected || landingRoot.landingOwner !== owner) {
                destroy();
                return;
            }

            if (!referencesCurrent()) {
                // replaced nodes need fresh references, not listeners retained on detached elements.
                destroy();
                initializeLanding();
            }
        }

        function initializeLifecycle() {
            const observer = new MutationObserver(onRootMutation);
            observers.add(observer);
            observer.observe(document.body, { childList: true, subtree: true });
            document.addEventListener('livewire:navigating', destroy, { signal: listeners.signal });
            reducedMotion.addEventListener('change', onMotionChange, { signal: listeners.signal });
        }

        function destroy() {
            if (destroyed) return;

            const ownsRoot = landingRoot.landingOwner === owner;
            destroyed = true;

            listeners.abort();
            observers.forEach(observer => observer.disconnect());
            observers.clear();
            timers.forEach(timer => window.clearTimeout(timer));
            timers.clear();
            frames.forEach(frame => cancelAnimationFrame(frame));
            frames.clear();

            // an obsolete owner releases its resources without resetting a newer owner's content.
            if (!ownsRoot) return;

            resetPass();
            showAllSections();
            completeTagline();
            main.style.removeProperty('--landing-closing-gap');
            // structural hero sizing stays valid; closing spacing returns to its natural base.
            if (landingRoot.landingOwner === owner) {
                delete landingRoot.landingOwner;
                delete landingRoot.dataset.landingReady;
            }
        }

        // ---------------------------------------------------------------------
        // initialization
        // ---------------------------------------------------------------------
        // install teardown before any feature can acquire asynchronous resources.
        landingRoot.dataset.landingReady = 'true';
        initializeLifecycle();
        initializeNavigation();
        initializeHeroLayout();
        initializePassInteraction();
        initializeSectionReveal();
        initializeTaglineReveal();
    })();
</script>
@endscript
