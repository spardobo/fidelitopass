## Linked issue

<!-- Replace N with the repository issue number, not the independent Project item ID. The issue must come from conversion of the same approved Backlog Project draft item; no direct-issue or add-existing route. Before Active, status:approved and the moving actor must be verified on both the issue assignees and Project Assignees display. Multiple PRs may reference this one issue. -->

- Intermediate pull request: `Refs #N` (or `Part of #N`); leave the issue open.
- Final pull request: `Closes #N` closes repository issue #N only after the item's selected criteria and integrated evidence are accepted. The Project item moves to Done separately under board policy or configured built-in GitHub automation on issue closure; closure alone guarantees neither Done nor acceptance.

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

- [ ] Relevant focused tests pass.
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

- [ ] The linked repository issue has `status:approved`.
- [ ] The branch follows the documented naming convention.
- [ ] The pull request has exactly one `type:*` label.
- [ ] Commits use Conventional Commits without attribution trailers.
