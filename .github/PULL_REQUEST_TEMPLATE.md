## Linked issue

<!-- Replace N with the repository issue number. Planned product/roadmap deliverable increments use the same approved Backlog Project draft converted to an issue; bounded minor changes, hotfixes, small bugs and explicitly owner-approved exceptions use an approved issue without a Project item. A new or expanded deliverable is not an exception. On both routes require status:approved and the responsible actor assigned on the issue; issue assignees are the source of truth. Multiple PRs may reference one issue. -->

- Intermediate pull request: `Refs #N` (or `Part of #N`); leave the issue open.
- Final pull request: `Closes #N` closes repository issue #N only after the item's selected criteria and integrated evidence are accepted. For planned work, reconcile Project Done separately after required exact-main CI; issue-only work has no Project transition. Closure alone guarantees neither completion nor acceptance.

## Delivery route

- [ ] Planned product/roadmap deliverable: approved Backlog Project draft converted in place to this issue.
- [ ] Bounded minor change, hotfix, small bug or explicit owner-approved exception: approved issue → branch → PR without a Project item (explain why this is not a new/expanded deliverable).

## Type

<!-- Select one type and apply exactly one matching type:* label. -->

- [ ] Bug fix
- [ ] New feature
- [ ] Documentation only
- [ ] Code refactoring
- [ ] Maintenance or tooling
- [ ] Breaking change

## Summary

- Describe the observable outcome.
- Explain why this change is needed.

## Changes

| File or area | Change |
|---|---|
| `path/to/file` | Describe the focused change. |

## Verification

- [ ] Relevant focused tests pass (or explain why not applicable).
- [ ] Required local quality gates pass.
- [ ] Documentation is current when behavior or configuration changed.

## Requirement impact

- Applicable original requirement IDs (or `None` with non-product scope justification):
- Selected criteria evidenced by this PR, with links to tests/demonstrations and the item issue:
- Remaining criteria or follow-up work (if any):
- A merged PR or closed issue does not alone establish acceptance of every criterion of a linked requirement; documentation-only work does not verify product behaviour or REQ-QLT-001.

## Risk and follow-up

- Risk: Describe the main failure or regression risk.
- Excluded: Record intentionally deferred work.

## Contributor checklist

- [ ] The linked repository issue has `status:approved` and its responsible actor assigned; Project assignment is not a separate gate.
- [ ] The selected route above fits the scope; planned work has approved same-item draft conversion, while issue-only work records explicit approval without fake Project fields/transitions.
- [ ] The branch follows the documented naming convention.
- [ ] The pull request has exactly one `type:*` label.
- [ ] Commits use Conventional Commits without attribution trailers.
- [ ] Required PR checks and owner review precede explicit owner merge order; completion awaits required CI on the exact resulting main commit. Record failed gates and recovery on the issue/PR for issue-only work, not on a fictional board item.
