# Selective learning procedure

## Recover only relevant context

1. Select current responsibilities and authorized paths through [project routing](../../../AGENTS.md#intent-routing). Read the matching owners, not every skill.
2. Read [learning.jsonl](../learning.jsonl) directly. Filter by scope paths intersecting the affected files or their parent directories; inspect only pertinent entries. A file reader and text filtering are sufficient; no provider, Engram or Gentle tool is required.
3. For applied or integrated entries, follow the destination pointer only when pertinent. The destination owner, not the registry, is authoritative. Discarded and superseded entries are not active guidance. Candidates and pending approvals are unaccepted evidence, not instructions.
4. Reconcile every recovered finding with current owner text, source and observed validation. Stale or conflicting evidence cannot override an owner. Ask about unresolved intent before the affected edit.

Use optional external memories only as leads requiring repository verification. Do not import old memories, transcripts, secrets, credentials, personal data or token metrics into the registry.

## Classify and choose once

Classify a user observation before extracting reusable guidance:

- **General correction:** an explicit general instruction can qualify on its first occurrence. For an incident without explicit general intent, establish the cause and supported scope; never universalize ambiguity.
- **Permanent preference:** persist only when enduring intent and scope are clear and compatible with the owner. Uncertain duration stays provisional.
- **One-off exception:** use only within its approved task; it grants no future permission.
- **Exploratory question:** answer or investigate in scope; the question itself is not an approved correction.

At a relevant work close, including a blocked or partial handoff, perform one bounded review of actual errors, rework, discoveries and validation results. Do not run another audit because this review found a learning. Skip trivial observations, already-owned rules and duplicate entries. An empty registry is a valid outcome; do not invent learnings to demonstrate the mechanism.

For a missed existing instruction, diagnose whether owner selection, reading or application failed. Propose or apply the smallest relevant route/procedure repair under the same permission gate; do not copy the instruction into every skill. If evidence is insufficient, identify the hypothesis and needed verification without making it normative.

## Choose the owner and permission

Use the existing PHP, Blade, JavaScript, delivery, starter and shared owners through project routing; do not maintain a second owner table. Starter maintenance requires verified provenance and specific authorization when external ownership is unresolved.

Within the selected owner, place activation, decision gates and execution procedure in `SKILL.md`; technical HOW in its implementation-details reference; proven project recipes in an existing project-recipes reference; illustrative calibration in examples. Do not create a generic knowledge tree or promote a hypothesis into a recipe. Shared conventions remain with shared code quality.

- **Apply:** only a small, related, evidenced procedural improvement within the current explicit edit scope, without changing contracts, conventions, permissions or functionality. Preserve valid behavior and instruction conditions; arbitrary shortening is not evidence of equivalence.
- **Propose:** give the exact destination, evidence, proposed change and needed approval when it is outside that boundary. Normative contracts, curated guidance, standards, security, data, UX, quality and delivery policy require specific owner approval and their documentary owner; a skill is not a competing authority. Hooks, CI/gates, dependencies, deployment, global/third-party configuration and changes to the agent's own permissions also require specific approval. Current construction/delivery authorization does not grant future publication or merge permission.
- **Do not persist:** discard trivial, duplicate, unverified or task-only material when a record would not aid scoped recovery. A provisional candidate is permitted only when a concrete unresolved verification need is worth recovering; label it as such.

Workers report findings and evidence without registry writes. The coordinator consolidates accepted findings once, checks for semantic duplicates, and makes only authorized writes. A record never authorizes its own destination change. Use [documentation ownership](../../../docs/documentation-standard.md#knowledge-ownership) and [delivery completion](../../../docs/development/workflow.md#done-criteria) rather than redefining them here.

## Instruction hygiene

After changing project instructions, review the affected entrypoints, changed references and direct consumers once. Review unchanged owners only to resolve a concrete overlap or conflict. A global audit requires an explicit request; a scoped learning review does not authorize one. Use the existing permission gate for edits, not the audit itself as approval.

1. Establish the affected responsibilities, authoritative owners, activation conditions and required versus conditional reads before editing. Select representative positive, negative and mixed tasks for comparison.
2. Check the affected set for the following issues; consult references only when the finding requires them.

| Signal | Check |
| --- | --- |
| Duplication | Compare meaning and authority, not repeated words. Distinguish a short routing summary from a competing rule or a copied procedure. |
| Contradiction | Compare preconditions, required actions, exclusions, exceptions and approval boundaries against the owner. Do not resolve uncertain intent by deleting a condition. |
| Obsolete content | Verify paths, anchors and source-dependent claims against current files and installed configuration; preserve valid historical evidence in its existing home. |
| Incorrect activation | Trace tasks that should and should not load each skill, including mixed work and scoped recovery. Check that portable routing actually reaches the intended owner. |
| Unnecessary mandatory reads | Trace each unconditional load to the decision it serves. Keep required contracts; make unrelated examples or specialized references conditional only when their existing intent permits it. |

3. Choose one disposition for each finding:

| Disposition | Use when |
| --- | --- |
| Conserve | The text carries a necessary condition, exception, curated example or useful routing cue, or no safe improvement is demonstrated. Record the reason in work evidence. |
| Clarify | Wording or activation is ambiguous but its intended behavior and owner are established. Preserve the original decision boundary. |
| Consolidate | The same rule has competing homes. Retain the authoritative owner and replace dependent copies with a precise route, preserving each consumer's conditions. |
| Move to a reference | Specialized HOW, background or examples are not needed at every activation. Keep a precise conditional link; moving text that remains unconditionally loaded is not a context saving. |

4. Compare before and after using the same tasks: a PHP-only server change, Blade-only presentation change, JavaScript-only quality script, and mixed Livewire change. Record selected skills, mandatory references, conditional owner sections and expected behavior, including exclusions and missing-source/approval stops. Add a case for each changed trigger or exception. A smaller load is acceptable only when the same required behavior remains reachable and applied.
5. Verify edited paths and anchors, owner authority, readability and applicable structural checks. Preserve shared code-quality conventions, meaningful contracts and curated examples; do not replace concrete rules with generic advice or shorten to an arbitrary quota. Report unresolved conflicts without declaring equivalence.

Keep findings, retained-item reasons and comparisons in issue/PR evidence, not normative session history. When context instrumentation is available, measure the same actually supplied file set before and after. Name the measurement boundary and distinguish measured tokens, estimates, word/byte counts and unmeasured runtime context. Do not claim savings from relocation or file size alone. Optional diagnostics such as agnix require verified availability and suitability; neither installation nor blanket autofix is part of this procedure.

## Compact JSONL contract

Keep one JSON object per nonblank line in [learning.jsonl](../learning.jsonl). Start empty unless a genuine evidenced finding qualifies. Maintain one current entry per learning: reuse its stable ID and update that line in place; Git history owns prior states. Check semantic duplicates as well as IDs.

Use exactly these fields:

| Field | Meaning and type |
| --- | --- |
| `id` | Stable lowercase kebab-case string; unique across current entries. Do not renumber on status changes. |
| `scope` | Nonempty array of existing repository-relative file or directory paths, without globs or anchors. Use a parent directory when the responsibility covers future files. |
| `learning` | Nonempty concise finding or provisional hypothesis. After promotion, replace the rule with a short outcome; retain the owner pointer in destination. |
| `evidence` | Nonempty array of concise verifiable observations or source/check references. Do not store raw conversations. |
| `destination` | Existing repository-relative owner file, optionally with `#heading`, or `null` before owner selection or for discarded/superseded entries. Pending approval, local application and integration require an owner. |
| `status` | One of the states below; state describes disposition, not authority. |
| `validation` | Object with exactly `confidence`, `checks`, `delivery`: confidence is `proven` or `provisional`; checks is a nonempty array of observed results or explicitly pending verification; delivery is evidence text or `null`. |

Paths must stay inside the repository. Keep evidence/validation concise but sufficient to distinguish an observed pass, failure, manual inspection and an unrun check. `proven` means the specific scoped finding is established by evidence, not that all historical code complies. `provisional` identifies an unresolved hypothesis or missing verification; state the gap in checks.

| Status | Meaning |
| --- | --- |
| `candidate` | Potentially reusable finding under evaluation; not active guidance. |
| `pending_approval` | Named destination/change needs specific approval; no destination edit is implied. |
| `applied_locally` | Authorized owner change made and locally verified; requires proven confidence, not a delivery claim. |
| `integrated` | Owner change delivered with evidence under the existing delivery owner; requires proven confidence and nonempty delivery evidence. Editing, a commit or local checks alone do not qualify. |
| `discarded` | Not reusable, duplicate or unsupported; retain a short reason only if useful for future recovery. |
| `superseded` | Replaced by current owner guidance or another entry; give the replacement pointer/reason, not an active rule. |

Promotion makes the destination owner authoritative. In applied/integrated records, keep only outcome, evidence, scope and pointer; do not retain a second normative rule. Mark superseded findings when evidence changes instead of silently reusing them.

## Verification boundary

Use existing documentation tooling for JSON parsing, exact field types, unique IDs, known states, repository scope/destination paths, evidence presence and local/integrated validation requirements. The validator checks structure, not evidence truth, semantic duplication, approval, delivery authenticity or heading anchors. Review those manually. Do not add a broad frontmatter/anchor platform.

Test runnable deterministic mechanics first, with observed RED/GREEN and relevant rejection cases. For passive skill prose, perform a manual structure and scenario review without artificial RED: selective recovery, explicit first correction, ambiguous incident, missed owner instruction, one-off exception, provisional finding, blocked close, local promotion and undelivered integration. Treat scenarios as simulations, not real learnings or runtime guarantees. Inspect names, logical paragraphs, conditions and cohesion; no measured context or code gains follow merely from creating this skill.
