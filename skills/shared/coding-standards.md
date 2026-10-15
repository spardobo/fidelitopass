# Shared coding standards

Apply this reusable contract to affected source, scripts, tests and configuration, only where each rule is meaningful. Keep language-specific syntax and lifecycle details in their language references. Domain, security, data and presentation outcomes remain with their document owners.

## Application by surface

- Apply PHP conventions to PHP source, tests and executable configuration; apply Blade conventions to markup and the PHP/JavaScript conventions to embedded code. A mixed file does not make its regions one language.
- Treat `.mjs` as JavaScript modules, not another language. Establish the module system and actual browser, Node or test-runner context before choosing APIs, types or resource handling. Do not impose browser lifecycle rules on Node scripts.
- Apply presentation conventions to CSS/Tailwind. Preserve selector scope, cascade, public tokens and computed effects; apply structure and naming without imposing callable documentation on styles or markup.
- For shell scripts, use their actual interpreter and native quoting/comment syntax; keep arguments, exit status, output and effects explicit. For JSON, YAML, XML, environment/configuration files and assets, preserve the consuming format, keys, precedence and public paths. Do not add unsupported comments, classes or DTOs merely to match application code.

Select affected language owners through [project intent routing](../../AGENTS.md#intent-routing). Preserve existing extensions, execution boundaries and configured tools; do not add a language, formatter or runtime for stylistic uniformity.

## Readability and cohesion

- Make responsibilities discoverable through descriptive names, cohesive regions and whitespace. Keep technical identifiers and documentation in English; use translated professional, neutral Spanish for visible UI copy, including accessibility text.
- Choose concise, natural names in their actual scope. Do not repeat information already supplied by the class, method or type; retain qualifiers that distinguish identities, credentials, units or roles. Prefer concrete operation verbs and domain terms over vague abstractions or stacked qualifiers. Name the value actually returned: a result array is not a persisted model. A plain `result` is sufficient when its local meaning is clear; do not invent an abstraction merely to name it.
- Name predicates for what they establish: format, existence, permission and authenticity are different questions. Make expensive work or external effects apparent rather than disguising them as stored-value access.
- Expose inputs, decisions, data flow and side effects as a top-to-bottom narrative. Make actual resource setup, ownership and cleanup order visible.
- Use one blank line between logical paragraphs, keeping steps of one idea together. Keep methods and handlers cohesive; split by responsibility, not line counts. Prefer early returns when they clarify branches. Avoid dense expressions, callback tangles, generic wrappers and arbitrary helper fragmentation.
- Assess the human reading path independently of tests and formatting. Require every abstraction, heading and extraction to reduce reading effort; passing behavior tests does not certify readability.
- Make responsibilities, inputs, decisions, outputs, side effects and failure paths understandable through names, structure and declaration contracts.

## Types and value organization

Use the smallest useful native types and shaped contracts at real boundaries:

- Keep scalar results, shaped arrays, local transformations, configuration and external payloads simple. Crossing a class boundary alone does not require a DTO. Use enums for meaningful closed sets, not every string supplied by a provider.
- Introduce a concrete DTO when named fields, repeated consumers or a useful invariant make the transfer clearer than a shaped array. Expose directly stored immutable values through public readonly properties, regardless of sensitivity. Do not add trivial accessors for stored values. Retain encapsulation for mutable state, actual access constraints and behavior. Property visibility does not define JSON, logging or HTTP disclosure. A domain value object also models a concept's validity and equality; an immutable container alone is not a value object.
- Give success, optional absence, expected rejection and operational failure truthful contracts. Do not make callers infer incompatible result shapes from unrelated flags, or introduce a generic Result/Either framework to avoid a small concrete contract.
- Map data to HTTP, provider or other transport formats at the owning adapter. Select intended fields explicitly; do not serialize an entire private operation object merely because the serializer accepts it.

Organize values by their actual context. Language owners define syntax, annotations and local folder conventions; do not introduce DTOs, repositories or layers universally.

Keep validation and authorization at their owning trust boundary. Type declarations and readonly data do not establish validated input, deep immutability or permission. Preserve meaningful distinctions between identities, credentials, units and uncertain outcomes.

## Design authority

Apply [architecture's design principles](../../docs/architecture/overview.md#pragmatic-design-principles): implement the simplest complete solution for the current requirement, not hypothetical generalization. This is a summary for routing, not an independent architecture policy; architecture remains authoritative.

## Framework-first gate

Prefer suitable native runtime, standard-library and installed framework capabilities before bespoke infrastructure. In Laravel and browser presentation, evaluate Laravel/Eloquent, Livewire, Flux, semantic HTML, Tailwind and Alpine for the responsibility they actually own; this is not an API checklist for Node or shell scripts.

Verify installed APIs and suitability for required semantics rather than assuming equivalence. Add custom behavior only for a concrete gap; preserve accessibility, server authority and existing lifecycle ownership where applicable. Do not duplicate native pipelines or framework rules in project guidance.

## Efficiency gate

- Identify relevant input sizes and cardinality `n`, traversal counts and query/provider/I/O counts. Distinguish CPU, memory, database rows examined, remote latency and lock duration; an algorithmic bound does not establish database query cost or end-to-end speed.
- Avoid N+1 queries, repeated scans, redundant lookups/provider calls and invariant recalculations. Choose native loops, collections, database aggregates or keyed indexes for the actual workload and memory cost, not as a style preference. Use existing primitives before manual caching or custom machinery.
- Preserve ordering, validation, authorization, concurrency, timing and other observable semantics when removing work.
- Optimize real bottlenecks using relevant measurements or tests. Do not select the lowest hypothetical Big O at the expense of clarity or claim performance gains from cleaner-looking source alone. Report unmeasured runtime assumptions as limits.

## Comments and documentation

Use names, types and structure first. Apply these rules to new code and refactors; do not add comments merely to satisfy a template.

- In PHP and JavaScript, use a brief lowercase English `//` comment without a final period where it clarifies a meaningful phase, required order or non-obvious rationale. Use the target language's native comment syntax elsewhere. Preserve actual acronym and identifier capitalization. Describe purpose rather than narrating each call.
- Keep each internal explanatory comment to one physical line; do not spread one explanation across consecutive inline comments. Put longer context in the owning declaration's documentation, using PHPDoc/JSDoc where applicable.
- Separate logical blocks with one blank line. Do not use decorative separator boxes, per-method headings, artificial groups or constructor/lifecycle/test-setup banners. In Blade, use the language's comment syntax only where a useful semantic region needs a label.

## Callable documentation

Apply proportional contracts under the same names-first guidance:

- Attach documentation directly to its declaration, with declaration attributes permitted. State the responsibility directly and proportionately; keep wording concise rather than restating the declaration. Language owners define required declaration coverage and tags.
- Preserve useful shaped/generic types, units, preconditions, effects, ownership, trust boundaries, uncertainty and cleanup obligations. Document a guarantee where it is established; do not imply that a local calculation validates input or authorizes delivery. Explain applicable failures without mechanically enumerating incidental exceptions. Language references own PHPDoc/JSDoc syntax, coverage and tooling details.
- Preserve meaningful asynchronous completion, rejection and resource-cleanup contracts, rather than reducing them to a native return type.
- Review each name, block and contract in context. Do not replace repetitive long names with ambiguous abbreviations or remove useful guarantees in pursuit of brevity. Tests for exact comment wording or placement are not behavioral evidence.

## Failures and logging

Keep ordinary false predicates, optional absence and empty success distinct from operational failure; do not invent exceptions for expected results. Catch an exception where the code owns meaningful recovery, translation, classification or cleanup, not to rethrow it unchanged at every call. Do not conceal unexpected failures with blanket catches, fallback defaults or empty guards.

Keep uncertainty explicit; a lost response, cancellation or exception does not prove rollback or authorize another external effect. Preserve established failure classifications, retry permissions, security and transaction contracts when translating failures; do not chain secret-bearing causes merely to retain debugging detail.

Use native logging and exception-reporting facilities with application-authored codes and explicit nonsecret context. Record a terminal failure once at the boundary that knows its outcome, not in every catch. A formatter represents data; it does not own domain-field catalogues or detect every secret. Do not introduce speculative safe-log wrappers, fallback emitters, custom emergency managers or queues to hide an unobserved failure. Operational and security owners define severity, correlation, actual failure visibility and privacy guarantees.

Separate each logging call from adjacent code with one blank line before and after. Do not add empty lines at enclosing block boundaries.

## Scoped review

Keep changed behavior distinguishable from a readability refactor. Preserve existing public contracts, exceptions, permission checks, ordering and lifecycle effects. Limit a change to its authorized behavior and directly necessary corrections; report unrelated cleanup separately. Review the complete changed reading path after formatting: names identify intent, related steps stay together, important effects are visible, and any extraction reduces the context needed to understand the caller.

Treat length, nesting and repeated syntax as review signals, not automatic limits. Do not add helpers, comments, defensive guards, layers or tests merely to satisfy a visual template. Useful type annotations supported by the target language and its configured tooling, and real framework contracts, take precedence over editorial preferences.

For behavior changes with a meaningful runnable deterministic regression, observe failure before implementation, then passing behavior and refactor while it remains green. Explain an unavailable runner or a purely structural/documentation exception; do not manufacture RED evidence.

Static analysis, formatting and documentation checks prove only their configured paths and rules; they do not establish runtime equivalence. Inspect actual scripts and configuration before claiming coverage of an extension, language or test directory. Do not assume a JavaScript formatter covers Blade, CSS or shell, or that PHP analysis includes tests.

Use the relevant runtime boundary for proof: deterministic unit tests for pure rules, database integration for locking/persistence, browser execution for rendered interaction and real filesystem fixtures for path rules. Isolated doubles do not certify framework bootstrap, database behavior or a provider deployment. Report failed, skipped and unavailable checks and remaining uncertainty without substituting source-shape assertions for behavior.
