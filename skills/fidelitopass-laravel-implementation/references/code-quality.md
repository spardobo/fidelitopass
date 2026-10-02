# Shared code quality

Apply this shared, reusable cross-language contract to affected PHP, Blade and JavaScript, including scripts and tests. Keep language-specific syntax and lifecycle details in their language references. Domain, security, data and presentation outcomes remain with their document owners.

## Readability and cohesion

- Make responsibilities discoverable through descriptive names, cohesive regions and whitespace. Keep technical identifiers and documentation in English; use translated professional, neutral Spanish for visible UI copy, including accessibility text.
- Expose inputs, decisions, data flow and side effects as a top-to-bottom narrative. Make actual resource setup, ownership and cleanup order visible.
- Keep methods and handlers cohesive; split by responsibility, not line counts. Prefer early returns when they clarify branches. Avoid dense expressions, callback tangles, generic wrappers and arbitrary helper fragmentation.
- Assess the human reading path independently of tests and formatting. Require every abstraction, heading and extraction to reduce reading effort; passing behavior tests does not certify readability.

## Pragmatic design gates

- Apply KISS: choose the simplest correct conventional solution, not the shortest source or fewest functions. Keep a multi-step operation together when it has one responsibility.
- Apply YAGNI: implement current requirements, not hypothetical providers, rule engines, base classes or extension points. Do not excuse missing security, applicable tests or readable source as future work.
- Apply DRY to shared knowledge that must change together, not merely similar-looking lines. Prefer local duplication over an abstraction with unrelated branches or flags.
- Apply SRP to cohesive reasons to change; do not split one operation into competing boundary owners. Apply OCP only to real variation. Preserve caller contracts, preconditions, results, exceptions and side effects under LSP. Under ISP, expose only the contract a consumer needs. Under DIP, isolate external details only at a demonstrated boundary.
- Default to concrete dependency injection and framework resolution. Add an interface only for current interchangeable implementations or meaningful external/testing isolation that concrete injection and framework-native fakes cannot reasonably provide. State what needs isolation and why existing tools are insufficient. Mock convenience, slogans and future replacement alone do not qualify.
- When an interface qualifies, keep its consumer contract focused and verify substitutions. Do not add unused implementations, artificial repositories or a layer per collaborator.
- Keep abstraction, control-flow complexity, state and side effects proportionate to the actual responsibility. Avoid empty scaffolding and speculative defensive machinery.

## Framework-first gate

Prefer Laravel/Eloquent, Livewire, Flux, semantic HTML, Tailwind and Alpine capabilities before bespoke infrastructure, CSS or JavaScript. Verify installed APIs and suitability for required semantics rather than assuming equivalence. Add custom behavior only for a concrete gap; preserve accessibility, server authority and existing lifecycle ownership. Do not duplicate native pipelines or framework rules in project guidance.

## Efficiency gate

- Identify relevant input sizes and cardinality `n`, traversal counts, query/provider/I/O counts and space costs. Account for database round trips and real workload limits, not only algorithmic notation.
- Avoid N+1 queries, repeated scans, redundant lookups/provider calls and invariant recalculations. Use framework query/collection primitives before manual caching or custom machinery.
- Preserve ordering, validation, authorization, concurrency, timing and other observable semantics when removing work.
- Optimize real bottlenecks using relevant measurements or tests. Do not select the lowest hypothetical Big O at the expense of clarity or claim performance gains from cleaner-looking source alone. Report unmeasured runtime assumptions as limits.

## Comment purposes

Use names, types and structure first; distinguish these purposes instead of substituting one for another:

| Purpose | Gate |
| --- | --- |
| Responsibility group | Locate a major cohesive group of multiple collaborating elements: state, configuration, functions or handlers. |
| API/function contract | Explain public use or information not expressed by signatures; apply the language owner's documentation scope. |
| Internal rationale | Explain a non-obvious constraint, required order, edge condition or units near the affected step. |

For useful major PHP/JavaScript responsibility groups, use a three-line `//` box with matching dashed separator lines around a short lowercase English label, indented with surrounding code. Do not box each helper, one isolated function or an empty decorative section. Small configuration groups may use a plain lowercase comment. Do not impose fixed labels, separator widths, helper counts or universal templates.

Use lowercase English semantic region comments in Blade only where they locate useful content/action regions, not every element. Keep explanations out of headings and place contracts in docblocks. Preserve meaningful existing contracts; avoid narration per operation and comments that restate code. Do not use source assertions for comment wording, boxes or placement as behavioral evidence.
