import assert from "node:assert/strict";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { test } from "node:test";

import { validateMarkdownLinks, validateRequirementIds } from "./validate-documentation.mjs";

function fixture() {
    const root = mkdtempSync(join(tmpdir(), "fidelitopass-quality-"));
    mkdirSync(join(root, "docs"));
    writeFileSync(
        join(root, "docs/requirements.md"),
        "#### REQ-TEC-001 — Provide a valid requirement\n",
    );
    writeFileSync(join(root, "README.md"), "[Requirements](docs/requirements.md)\n");

    return root;
}

test("relative Markdown links resolve from their source file", (context) => {
    const root = fixture();
    context.after(() => rmSync(root, { recursive: true, force: true }));
    assert.deepEqual(validateMarkdownLinks(root), []);

    writeFileSync(join(root, "README.md"), "[Missing](docs/missing.md)\n");
    assert.match(validateMarkdownLinks(root)[0], /references missing path/);
});

test("skill Markdown links and local inline paths resolve", (context) => {
    const root = fixture();
    context.after(() => rmSync(root, { recursive: true, force: true }));
    mkdirSync(join(root, "skills/example/references"), { recursive: true });
    writeFileSync(
        join(root, "skills/example/SKILL.md"),
        "[Broken](references/absent.md) and `references/missing.md`\n",
    );
    assert.equal(validateMarkdownLinks(root).length, 2);

    writeFileSync(
        join(root, "skills/example/references/valid.md"),
        "See `docs/requirements.md` and `references/valid.md`.\n",
    );
    writeFileSync(
        join(root, "skills/example/SKILL.md"),
        "[Valid](references/valid.md) and `references/valid.md`\n",
    );
    assert.deepEqual(validateMarkdownLinks(root), []);
});

test("repo-root documentation code paths are checked without matching examples or URLs", (context) => {
    const root = fixture();
    context.after(() => rmSync(root, { recursive: true, force: true }));
    writeFileSync(join(root, "README.md"), "Read `docs/missing.md` and `docs/requirements.md`.\n");
    assert.match(validateMarkdownLinks(root)[0], /docs\/missing\.md/);
    writeFileSync(
        join(root, "README.md"),
        "`docs/requirements.md` [External](https://example.com/no) [Anchor](#missing)\n```sh\ncat `docs/not-a-reference.md`\n```\n",
    );
    assert.deepEqual(validateMarkdownLinks(root), []);
});

test("requirement references must exist in the canonical register", (context) => {
    const root = fixture();
    context.after(() => rmSync(root, { recursive: true, force: true }));
    writeFileSync(join(root, "README.md"), "Implements REQ-TEC-001.\n");
    assert.deepEqual(validateRequirementIds(root), []);

    writeFileSync(join(root, "README.md"), "Implements REQ-TEC-999.\n");
    assert.match(validateRequirementIds(root)[0], /references unknown requirement/);
});

test("malformed and duplicate requirement identifiers fail", (context) => {
    const root = fixture();
    context.after(() => rmSync(root, { recursive: true, force: true }));
    writeFileSync(join(root, "README.md"), "Implements REQ-TEC--001.\n");
    assert.match(validateRequirementIds(root)[0], /malformed requirement identifier/);

    writeFileSync(
        join(root, "docs/requirements.md"),
        "#### REQ-TEC-001 — First\n#### REQ-TEC-001 — Duplicate\n",
    );
    assert.match(validateRequirementIds(root)[0], /defines REQ-TEC-001 more than once/);
});
