# Shared code quality

Apply this shared, reusable cross-language contract to affected PHP, Blade and JavaScript, including scripts and tests. Keep language-specific syntax and lifecycle details in their language references. Domain, security, data and presentation outcomes remain with their document owners.

## Readability and cohesion

- Make responsibilities discoverable through descriptive names, cohesive regions and whitespace. Keep technical identifiers and documentation in English; use translated professional, neutral Spanish for visible UI copy, including accessibility text.
- Expose inputs, decisions, data flow and side effects as a top-to-bottom narrative. Make actual resource setup, ownership and cleanup order visible.
- Use one blank line between logical paragraphs, keeping steps of one idea together. Keep methods and handlers cohesive; split by responsibility, not line counts. Prefer early returns when they clarify branches. Avoid dense expressions, callback tangles, generic wrappers and arbitrary helper fragmentation.
- Assess the human reading path independently of tests and formatting. Require every abstraction, heading and extraction to reduce reading effort; passing behavior tests does not certify readability.

## Design authority

Apply [architecture's design principles](../../docs/architecture/overview.md#pragmatic-design-principles): implement the simplest complete solution for the current requirement, not hypothetical generalization. This is a summary for routing, not an independent architecture policy; architecture remains authoritative.

## Framework-first gate

Prefer Laravel/Eloquent, Livewire, Flux, semantic HTML, Tailwind and Alpine capabilities before bespoke infrastructure, CSS or JavaScript. Verify installed APIs and suitability for required semantics rather than assuming equivalence. Add custom behavior only for a concrete gap; preserve accessibility, server authority and existing lifecycle ownership. Do not duplicate native pipelines or framework rules in project guidance.

## Efficiency gate

- Identify relevant input sizes and cardinality `n`, traversal counts, query/provider/I/O counts and space costs. Account for database round trips and real workload limits, not only algorithmic notation.
- Avoid N+1 queries, repeated scans, redundant lookups/provider calls and invariant recalculations. Use framework query/collection primitives before manual caching or custom machinery.
- Preserve ordering, validation, authorization, concurrency, timing and other observable semantics when removing work.
- Optimize real bottlenecks using relevant measurements or tests. Do not select the lowest hypothetical Big O at the expense of clarity or claim performance gains from cleaner-looking source alone. Report unmeasured runtime assumptions as limits.

## Comment purposes

Use names, types and structure first; distinguish these purposes instead of substituting one for another:

| Purpose               | Gate                                                                                                           |
| --------------------- | -------------------------------------------------------------------------------------------------------------- |
| Responsibility group  | Locate a major cohesive group of multiple collaborating elements: state, configuration, functions or handlers. |
| Callable contract | Follow the mandatory named-callable documentation contract below; language references own syntax and application details. |
| Internal rationale    | Explain a non-obvious constraint, required order, edge condition or units near the affected step.              |

For useful major PHP/JavaScript responsibility groups outside function, method, closure and callback bodies, use a three-line `//` box with matching dashed separator lines around a short lowercase English label, indented with surrounding code. Leave one blank line before and after the box. Inside bodies, use logical paragraphs and only necessary rationale comments. Keep each docblock immediately attached to its declaration, with declaration attributes permitted in PHP; never put a box between them. Do not box each helper, one isolated function or an empty decorative section. Small configuration groups may use a plain lowercase comment. Do not impose fixed labels, separator widths, helper counts or universal templates.

Use lowercase English semantic region comments in Blade only where they locate useful content/action regions, not every element. Keep explanations out of headings and place contracts in docblocks. Preserve meaningful existing contracts; avoid narration per operation and comments that restate code. Do not use source assertions for comment wording, boxes or placement as behavioral evidence.

## Callable documentation

Document every project-owned named function and method, including private helpers, constructors and accessors, in one PHPDoc or JSDoc block attached to its declaration. The block describes the callable and each parameter's type and purpose. For value-returning callables, add a precise `@return` or `@returns` type and a meaningful result description, including for generic or shaped results. Do not add a plain `void` return tag; describe necessary effects in the callable description. Keep meaningful asynchronous `Promise<void>` contracts because they describe completion and rejection behavior. Document only expected, applicable exceptions that cross the callable boundary; do not invent or mechanically list incidental framework exceptions.

Native signatures remain required and do not replace parameter descriptions. Preserve useful generic and array/object shape types, units, preconditions, side effects, ownership, trust boundaries and cleanup obligations where they affect callers. For asynchronous JavaScript, describe meaningful promise rejection behavior in the promise return contract; document synchronous throws only when they are expected and applicable.

This rule applies to named project-owned callables, not every anonymous closure or inline test callback. Document an anonymous callable only when it represents a named/reused contract or carries behavior, data shape, rejection or resource obligations that would otherwise be unclear. Use English technical documentation and keep each block attached to its declaration.

## Scoped review

Keep changed behavior distinguishable from a readability refactor. Preserve existing public contracts, exceptions, permission checks, ordering and lifecycle effects. Limit a change to its authorized behavior and directly necessary corrections; report unrelated cleanup separately. Review the complete changed reading path after formatting: names identify intent, related steps stay together, important effects are visible, and any extraction reduces the context needed to understand the caller.

Treat length, nesting and repeated syntax as review signals, not automatic limits. Do not add helpers, comments, defensive guards, layers or tests merely to satisfy a visual template. Useful type annotations supported by the target language and its configured tooling, and real framework contracts, take precedence over editorial preferences.

Static analysis, formatting and documentation checks prove only their own boundaries; they do not establish runtime equivalence. Require evidence at the relevant execution boundary for an equivalence claim, or report what remains unverified and why.
