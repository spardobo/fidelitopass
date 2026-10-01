<x-slot:description>
    {{ __('landing.page_description') }}
</x-slot:description>

<div id="page-top" class="landing-world min-h-screen text-[#F6F5F2]">
    <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-[#B7ABE4] focus:p-3 focus:text-[#181818]">
        {{ __('landing.navigation.skip_to_content') }}
    </a>

    <!-- header and navigation -->
    <header class="border-b border-white/10 bg-shell">
        <div class="mx-auto flex max-w-[68rem] items-center justify-between gap-4 px-6 py-6 md:px-8">
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
                <nav class="absolute right-0 top-full z-50 mt-3 flex w-56 flex-col gap-4 rounded-2xl landing-surface-neutral p-6 shadow-xl" aria-label="{{ __('landing.navigation.page_sections') }}">
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
    <main id="content" tabindex="-1" class="mx-auto max-w-[68rem] px-6 md:px-8">
        <!-- hero and conceptual pass -->
        <section id="home" aria-labelledby="hero-title" class="grid items-center gap-12 rounded-3xl landing-surface-large px-6 py-12 md:px-12 md:py-20 xl:grid-cols-[minmax(0,312px)_minmax(0,576px)] xl:gap-8">
            <div class="mx-auto flex w-full max-w-[576px] flex-col items-center gap-6 text-center xl:mx-0 xl:items-start xl:text-left">
                <span class="rounded-full border border-[#B7ABE4] px-3 py-2 text-xs font-semibold uppercase tracking-wider text-[#D8CEF5]">
                    {{ __('landing.hero.eyebrow') }}
                </span>
                <flux:heading level="1" id="hero-title" class="landing-role-hero max-w-[680px] tracking-tight">
                    {{ __('landing.hero.heading') }}
                </flux:heading>
                <flux:text size="lg" class="landing-role-body max-w-[680px] text-[#C4C4C4]">
                    {{ __('landing.hero.description') }}
                </flux:text>
                <flux:button variant="primary" href="{{ route('register') }}" class="landing-button landing-role-action landing-button-primary rounded-full transition-transform duration-300 hover:-translate-y-[3px] active:scale-[.98]">
                    {{ __('landing.actions.create_account') }}
                </flux:button>

                <p class="landing-role-support text-[#E0E0E0]">{{ __('landing.hero.sign_in_prompt') }} <a href="{{ route('login') }}" class="underline hover:text-[#D8CEF5]">{{ __('landing.hero.sign_in_link') }}</a></p>
            </div>

            <div class="mx-auto w-full max-w-[576px] xl:mx-0">
                <div class="landing-thumbnail" data-thumbnail>
                    <div class="landing-pass-scale">
                        <article aria-label="{{ __('landing.hero.pass_aria') }}" class="landing-pass flex flex-col gap-6 rounded-2xl p-6 shadow-2xl" data-pass>
                            <div class="flex items-start justify-between gap-4">
                                <h2 class="text-xl font-bold">
                                    {{ __('landing.hero.sample.business') }}
                                </h2>
                                <img src="{{ asset('logo_icon.svg') }}" alt="" class="size-10" width="40" height="40">
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

            </div>
        </section>

        <!-- benefits -->
        <section id="benefits" aria-labelledby="benefits-title" class="grid justify-items-center gap-8 py-20">
            <div class="max-w-[680px] text-center">
                <p class="mb-4 text-sm font-semibold text-[#D8CEF5]">
                    {{ __('landing.benefits.eyebrow') }}
                </p>
                <flux:heading level="2" id="benefits-title" class="landing-role-section">
                    {{ __('landing.benefits.proposition_heading') }}
                </flux:heading>
                <p class="mt-4 text-[#C4C4C4]">
                    {{ __('landing.benefits.problem') }}
                </p>
            </div>

            <div class="grid w-full gap-6 md:grid-cols-2">
                <article class="landing-info-card landing-surface-emphasis rounded-3xl p-8 transition-[transform,box-shadow] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)]">
                    <img src="{{ asset('benefit-challenges.svg') }}" alt="" class="mb-6 size-16" width="64" height="64">
                    <flux:heading level="3" class="landing-role-card">
                        {{ __('landing.benefits.challenge_heading') }}
                    </flux:heading>
                    <p class="mt-3">
                        {{ __('landing.benefits.challenge_description') }}
                    </p>
                </article>

                <article class="landing-info-card landing-surface-neutral rounded-3xl p-8 transition-[transform,box-shadow] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)]">
                    <img src="{{ asset('benefit-points.svg') }}" alt="" class="mb-6 size-16" width="64" height="64">
                    <flux:heading level="3" class="landing-role-card">
                        {{ __('landing.benefits.progress_heading') }}
                    </flux:heading>
                    <p class="mt-3 text-[#E0E0E0]">
                        {{ __('landing.benefits.progress_description') }}
                    </p>
                </article>
            </div>
        </section>

        <!-- editorial statement: locale-owned groups consume the unchanged word sequence -->
        <section aria-label="{{ __('landing.tagline.label') }}" class="py-16">
            @php($taglineWords = collect(__('landing.tagline.words')))
            <p class="landing-role-statement mx-auto text-center" data-tagline>
                @foreach (__('landing.tagline.presentation_groups') as $group)
                    <span class="landing-statement-{{ $group['role'] }}">
                        @foreach ($taglineWords->splice(0, $group['word_count']) as $word)
                            <span class="tagline-word inline-block" data-tagline-word>{{ $word }}</span>{{ $loop->last ? '' : ' ' }}
                        @endforeach
                    </span>{{ $loop->last ? '' : ' ' }}
                @endforeach
            </p>
        </section>

        <!-- how it works -->
        <section id="how-it-works" aria-labelledby="steps-title" class="py-20">
            <p class="text-sm font-semibold text-[#D8CEF5]">
                {{ __('landing.steps.eyebrow') }}
            </p>
            <h2 id="steps-title" class="landing-role-section mt-4">
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
                    <li class="landing-info-card landing-surface-neutral rounded-3xl p-8 transition-[transform,box-shadow] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)]">
                        <span class="text-3xl text-[#D8CEF5]">
                            {{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}
                        </span>
                        <h3 class="landing-role-card mt-8">
                            {{ $heading }}
                        </h3>
                        <p class="mt-3 text-[#E0E0E0]">
                            {{ $detail }}
                        </p>
                    </li>
                @endforeach
            </ol>
        </section>

        <section id="challenges" aria-labelledby="challenge-title" class="landing-surface-emphasis rounded-3xl p-8 md:p-12">
            <p class="text-sm font-semibold text-[#D8CEF5]">
                {{ __('landing.challenge.eyebrow') }}
            </p>
            <h2 id="challenge-title" class="landing-role-section mt-4">
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
            <h2 id="wallet-title" class="landing-role-section mt-4">
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
            <h2 id="faq-title" class="landing-role-section mb-8">
                {{ __('landing.faq.heading') }}
            </h2>
            <div class="grid gap-3">
                @foreach (__('landing.faq.items') as $item)
                    <details class="landing-surface-neutral rounded-2xl p-6">
                        <summary class="cursor-pointer rounded-sm font-semibold transition-colors duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] hover:text-[#D8CEF5] focus-visible:text-[#D8CEF5]">
                            <span>
                                {{ $item['question'] }}
                            </span>
                            <flux:icon.chevron-down class="size-5" />
                        </summary>
                        <p class="mt-4 text-[#E0E0E0]">
                            {{ $item['answer'] }}
                        </p>
                    </details>
                @endforeach
            </div>
        </section>

        <!-- business call to action -->
        <section id="business" aria-labelledby="business-title" class="rounded-3xl p-8 md:p-12">
            <h2 id="business-title" class="landing-role-section">
                {{ __('landing.business_cta.heading') }}
            </h2>
            <p class="mt-4">
                {{ __('landing.business_cta.description') }}
            </p>
            <flux:button variant="primary" href="{{ route('register') }}" class="landing-button landing-role-action landing-cta-button mt-6 rounded-full text-white transition-transform duration-300 hover:-translate-y-[3px] hover:bg-[#303030] active:scale-[.98]">
                {{ __('landing.actions.create_account') }}
            </flux:button>
        </section>
    </main>

    <!-- footer -->
    <footer class="mx-auto flex max-w-[68rem] flex-wrap items-center gap-6 px-6 py-6 text-sm text-[#E0E0E0] md:px-8">
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
            const extraGapPx = Math.max(0, Math.min(
                (availableHeightPx - hero.getBoundingClientRect().height) / 2,
                sectionSpacePx - baseGapPx,
            ));
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
