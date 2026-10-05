# JavaScript and Alpine implementation

Apply [shared code quality](../../shared/code-quality.md) before these client-specific conventions. Consult the [PHP owner](../../fidelitopass-laravel-livewire-implementation/SKILL.md) for server boundaries and the [Blade/Flux owner](../../fidelitopass-blade-flux-implementation/SKILL.md) and its [presentation reference](../../fidelitopass-blade-flux-implementation/references/implementation-details.md) for markup, widgets, styling and text composition in mixed components. Do not duplicate those contracts here. Apply source readability to JavaScript tests and build/quality scripts too; apply DOM, animation and component-lifetime rules only where those resources exist.

## Native behavior and authority

- Prefer Livewire native reactive state and interactions for server-driven behavior. Evaluate supported Flux widgets before rebuilding their interactions. Use Alpine for small transient local presentation before bespoke JavaScript; justify custom behavior by a concrete unmet requirement.
- Keep consequential rules, validation, authorization and mutations at their owning server boundary. Do not duplicate authoritative rules in hydrated state, browser calculations, local storage or Alpine. Client hints and disabled controls are presentation, not security boundaries.
- Verify version-sensitive behavior against installed versions/configuration, targeted framework guidance and installed source when necessary. Do not guess hook names, initialization arguments, cleanup guarantees or widget APIs. Framework guidance owns syntax; this reference does not freeze a version-specific adapter.
- Keep the existing native Livewire/Flux/Alpine and Vite asset pipeline. Colocate necessary component-local behavior in native component scripts for the actual format (`@script` for class-based views; the documented script structure for Livewire 4 single/multi-file components); reserve the configured shared entry for genuinely shared behavior, not merely a long script. Add no separate bundle, global inline behavior, second Alpine runtime, generic SPA machinery or package installation.
- Isolate camera/scanner code within the validation component; submit only the identifier/token to the same server-owned lookup boundary, not a client-authorized mutation. Read the UI owner's [Required order](../../../docs/ui-ux-guidelines.md#required-order), [Camera error](../../../docs/ui-ux-guidelines.md#camera-error) and [Customer-pass result](../../../docs/ui-ux-guidelines.md#customer-pass-result) for identification/confirmation/result stages, visible manual fallback, stale-read handling, focus and device/listener release. Read the architecture's [Visit validation transaction](../../../docs/architecture/overview.md#visit-validation-transaction) and security's [Credential classes](../../../docs/architecture/security.md#credential-classes) for read-only identification, explicit confirmation and authoritative mutation. Do not invent a second scanner policy here.

## Root contract and dynamic DOM

- Establish the component root, initialization entry and actual lifetime before installing behavior. Use the native owning root, such as `$wire.$el` in its supported component script context. Keep a local closure when useful; introduce no global controller merely to organize local work.
- Resolve stable DOM references together within that root. Scope queries to the owner; distinguish component instances and imperative targets with stable, meaningful identity. Keep IDs, fragments, data hooks and linked tests in English. Do not let repeated components select or dispose each other's elements.
- Verify that the initializer receives a valid root and runs when required nodes exist. Validate only actually uncertain inputs, optional nodes or browser capabilities. Preserve required contracts rather than hiding broken markup or integration with blanket null checks, catches or defensive no-ops.
- Account for morphing, conditional rendering and navigation only where they affect the behavior. Re-resolve replaced nodes rather than retain stale references; preserve imperative identity where the integration needs it through supported framework facilities. Reconcile custom DOM ownership with framework rendering instead of imitating hydration or attaching a universal document observer.
- Use duplicate-initialization protection only when the real entry paths can overlap. Scope it to the instance and release it without affecting a replacement instance. Do not prescribe a WeakMap registry, controller base class or universal toggle recipe.

## Readable execution and contracts

- Expose purpose, inputs, decisions, setup, execution and actual destruction as a top-to-bottom semantic narrative. Group configuration with clear units, stable references, real lifecycle state/resources and cohesive feature behavior where they exist. Keep initialization calls together in dependency order and make cleanup easy to locate; omit empty scaffolding and inert lifecycle stubs.
- Use named initializers/handlers when they clarify feature responsibilities. Keep calculations and handlers cohesive, use meaningful names and early returns, and avoid nested callback tangles. Neither one oversized function per feature nor arbitrary helper counts establishes readability.
- Extract a calculation or scheduling helper only for real duplication or a non-obvious operation whose intent becomes clearer. Preserve geometry-read timing, DOM-write ordering and pending-work ownership. Add no generic helper collection, ornamental wrapper or class factory.
- Apply the shared owner's three distinct comment purposes. Use its three-line lowercase English responsibility boxes only for useful major groups of multiple collaborating items, never per helper. Keep contracts in docblocks and internal rationale beside the relevant step; do not duplicate the shared heading policy.
- Use JSDoc for non-obvious callable and resource contracts: meaningful array/object shapes, inputs/results, units, preconditions, side effects, subscription ownership or cleanup obligations not clear from names and code. Preserve useful existing contracts; do not annotate every JavaScript function or narrate obvious signatures.

## Resource ownership and disposal

- When asynchronous work is replaceable, invalidate the old intent as soon as new intent occurs, before any debounce interval or replacement request starts.
- Gate result publication and loading/error transitions after each `await`, including in `finally`, on the current intent and live owning instance. An obsolete `finally` must not clear its successor's loading state.
- Do not treat abort/cancellation alone as proof of current intent. Prefer native behavior; where a gap remains, use the smallest local intent check, not a generic request framework.
- Give every owned listener, timer, observer, animation frame and subscription an identifiable owner and cleanup path. Prefer supported signal-bound listeners with an `AbortController` where appropriate; otherwise retain the removal/unsubscribe handle. Track pending work only to the extent the actual resources require.
- Prefer native component/Alpine lifecycle facilities verified for the installed version. Tie cleanup to the actual lifetime, including root removal and Livewire navigation when relevant, not merely a page-level event. Use a root-removal observer only for a demonstrated gap; own and disconnect it too.
- Keep related cleanup in one discoverable routine. Make initialization and teardown idempotent when duplicate entry, overlapping navigation/removal or re-entrancy is a real risk. Invalidate the instance before cancelling work when re-entrant callbacks could restart it. Use the minimum disposal state needed; do not duplicate state already guaranteed by the framework.
- Abort/remove listeners, disconnect observers, unsubscribe and cancel pending timers/frames, then clear owned tracking. Remove completed timers/frames from tracking too. Reset owned transient transforms/reveal styles so still-connected content is safely visible, and release only that instance's initialization guard.
- Guard delayed/observer callbacks and asynchronous continuations against disposal and detached/replaced owners before reading/writing DOM or scheduling more work when they can outlive the instance. Prevent a stale completion from mutating a replacement instance. Do not impose redundant guards on synchronous calculations with no lifetime risk.

## Browser work and efficiency

- Identify relevant element cardinality, repeated traversals, high-frequency input/scroll/frame loops and actual DOM work. Avoid repeated root scans, duplicate listeners, invariant recalculation and unnecessary observer/timer resources.
- Separate geometry reads from writes where interleaving forces layout. Reuse measurements only while valid; invalidate geometry when actual layout changes, node replacement, resizing or asynchronous content/font changes affect the feature. Do not add a universal geometry cache or invalidation subsystem.
- Coalesce high-frequency work with supported scheduling when warranted, own any queued frame and preserve observable timing and interaction semantics. Optimize measured bottlenecks, not arbitrary source counts or hypothetical Big O. Report unmeasured performance assumptions as limits.
- Preserve applicable owner outcomes for keyboard/anchor access, focus, touch, natural layout growth, no-JavaScript content and optional-API fallbacks. For non-essential animation, account for reduced motion, including preference changes while work is pending where relevant. Cleanup must not strand hidden content. These are browser-feature concerns, not requirements for every non-DOM module.

## Data, copy and outcome owners

- Serialize server data into JavaScript with supported secure framework facilities and preserve escaping for the actual output context. Do not concatenate guessed JavaScript strings, bypass escaping or treat serialized client input as trusted server state.
- Supply professional Spanish visible copy and accessibility text through the proper Laravel/framework translation source, not repeated raw literals or browser-side phrase matching. Keep technical identifiers and internal comments English; leave markup text/attribute composition to the Blade owner.
- Before an affected UI change, select the relevant UI owner sections: [Form behaviour](../../../docs/ui-ux-guidelines.md#form-behaviour), [Loading feedback](../../../docs/ui-ux-guidelines.md#loading-and-perceived-responsiveness), [Accessibility baseline](../../../docs/ui-ux-guidelines.md#accessibility-baseline), [Motion](../../../docs/ui-ux-guidelines.md#motion) and the actual screen interaction. Do not copy domain or presentation outcomes into this skill.
- Use [Documentation routing](../../../AGENTS.md#documentation-routing) for security, domain and other necessary owners. Stop before the affected edit for an unreadable source or genuine policy conflict. Global conventions govern authorized new work; they do not authorize unrelated rollout or certify historical source compliance.

## Verification boundary

- For affected replaceable asynchronous work, verify completion during the replacement's debounce interval and out-of-order resolutions/rejections. Include an old `finally` while its successor is loading; stale work must not publish results/errors or clear that loading state.
- Use the [Quality strategy](../../../docs/quality-strategy.md#test-design) to select the cheapest meaningful behavior boundary. Keep deterministic calculations and non-DOM scripts in focused unit tests; use browser evidence for actual geometry, focus, navigation, morphing and device interactions that unit mocks cannot prove. Avoid duplicating identical assertions across layers without a reason.
- Test affected alternate/negative behavior, including optional-API/no-JavaScript fallbacks, keyboard/touch/reduced-motion handling, dynamic DOM, duplicate initialization, resource cleanup and stale callbacks where those risks exist. Assert observable outcomes, not initializer names, helper counts, source ordering, comment boxes, fixed heading labels or formatting.
- Follow [Browser coverage](../../../docs/quality-strategy.md#browser-coverage) and nearby existing tooling. Preserve the native browser runner's Docker ownership; inspect its execution boundary and do not nest it in Sail. Run application-development PHP/Composer/Artisan/npm commands through Sail under the [project execution contract](../../../AGENTS.md#stack-and-execution); report unavailable runners instead of substituting host tools.
- Add no runner upgrades or dependencies for consistency. Inspect source readability independently of passing behavior tests. Documentation/link checks establish structural evidence only, not runtime browser proof.

## Node filesystem boundaries

Apply these checks to Node quality scripts that accept repository-relative paths; the filesystem rules do not apply to browser code.

- Preserve lexical restrictions first: reject disallowed absolute paths, traversal segments, separators and anchors. Allow an anchor only for a field whose contract permits it; resolve its file portion without claiming the heading exists.
- Resolve both the repository root and target with `realpathSync`. Check `path.relative` by segments: reject an absolute result, `..` or a result beginning with `..` plus the platform separator. A textual root prefix accepts similarly named sibling directories and is insufficient. Accept internal links and roots accessed through a link.
- Validate the canonical location and required file/directory role before reading content. Treat missing, dangling or looping targets as rejection; propagate unexpected filesystem failures rather than turning them into a pass.
- For an optional file, do not infer safe absence from `existsSync` alone: it follows links and hides dangling entries. Inspect the entry and nearest existing ancestor with `lstatSync`; reject broken or escaped ancestry and non-directory parents before accepting an absent file.
- Use controlled temporary fixtures for ordinary paths, traversal, permitted anchors, internal/external direct and intermediate links, broken links, similar-prefix siblings and linked roots. Include valid external content so a location rejection cannot be mistaken for a content error; observe RED before the fix when a runnable regression exists.
- Canonical checks are validation, not a sandbox or protection against concurrent filesystem replacement. Do not claim race-proof containment between checking and reading.

## Language and asynchronous contracts

Follow configured lint/format rules. Without an explicit competing convention, use four spaces, semicolons, single-quoted literals, const by default and let only for reassignment. Use camelCase names, concrete verbs, meaningful predicates, plural collections and units when important. Avoid nested ternaries and short-circuit mutations that hide decisions or effects.

Within a function, method, factory or callback, organize statements into logical paragraphs with one blank line; separator boxes belong outside bodies. Named functions improve actual responsibilities, not every expression. A component-local script can stay local regardless of length when ownership and testing remain clear.

Document exported/reused APIs and non-obvious resource/data contracts. Preserve useful JSDoc types, including reusable typedefs when the actual tooling benefits; do not create a class or repeat a giant object shape solely to avoid a valid annotation. Describe promise rejection separately from handled operational errors. A fire-and-forget void expression does not handle a rejection.

For custom fetch code, validate response status and external payload at its boundary. Keep empty success distinct from failure. Abort does not undo a server write or prove a response is current. Never use forEach(async ...) when completion must be awaited; select sequential or parallel work by actual dependency and limit concurrency when volume warrants it.

Avoid repeated linear lookup inside growing collections when one simple keyed index expresses the association. Define input sizes, average-case assumptions and memory trade-offs; do not require a benchmark to remove obvious wasted work or claim measured latency without evidence. Prefer native framework behavior before adding custom I/O, watchers, observers or caches.

Use Alpine inline state for small transient behavior and an Alpine data provider when several methods or lifecycle resources genuinely need one owner. Keep technical handles outside reactive UI state when appropriate. Do not use entanglement, a second Alpine startup or multiple request channels merely to synchronize copies of server-owned state. Verify script timing and cleanup against the installed component format.

Read [examples](readability-examples.md) only when calibration helps. Use the smallest applicable pattern; no example mandates a factory, Map, AbortController or lifecycle machinery for a simple synchronous interaction.
