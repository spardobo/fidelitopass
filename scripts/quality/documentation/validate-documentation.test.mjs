import assert from "node:assert/strict";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { test } from "node:test";

import {
    validateLearningRecords,
    validateMarkdownLinks,
    validateRepository,
    validateRequirementIds,
} from "./validate-documentation.mjs";

// Synthetic records exercise mechanics only; they are not project learnings.
function learningRecord(overrides = {}) {
    return {
        id: "synthetic-correction",
        scope: ["docs"],
        learning: "Synthetic reusable correction for validator tests.",
        evidence: ["Synthetic observed check."],
        destination: "docs/requirements.md",
        status: "candidate",
        validation: { confidence: "provisional", checks: ["Synthetic check."], delivery: null },
        ...overrides,
    };
}

function fixture() {
    const root = mkdtempSync(join(tmpdir(), "fidelitopass-quality-"));
    mkdirSync(join(root, "docs"));
    writeFileSync(
        join(root, "docs/requirements.md"),
        "- Total requirements: **1**.\n## Requirement register\n| 1 | REQ-TEC-001 | Must |\n## Detailed requirements\n#### REQ-TEC-001 — Provide a valid requirement\n",
    );
    writeFileSync(join(root, "README.md"), "[Requirements](docs/requirements.md)\n");

    return root;
}

test("learning registry accepts empty input and one current entry per stable identity", (context) => {
    const root = fixture();
    context.after(() => rmSync(root, { recursive: true, force: true }));
    assert.deepEqual(validateLearningRecords(root, ""), []);
    const record = JSON.stringify(learningRecord());
    assert.deepEqual(validateLearningRecords(root, `${record}\n`), []);
    assert.match(validateLearningRecords(root, `${record}\n${record}`).join("\n"), /duplicate id/);
    assert.match(
        validateLearningRecords(root, "{broken}\nnull").join("\n"),
        /line 1.*invalid JSON/,
    );
    assert.match(validateLearningRecords(root, "null").join("\n"), /line 1.*object/);
    assert.match(validateLearningRecords(root, "[]").join("\n"), /object/);
});

test("learning records reject invalid fields, evidence and nonlocal destinations", (context) => {
    const root = fixture();
    context.after(() => rmSync(root, { recursive: true, force: true }));
    const invalidRecords = [
        [learningRecord({ id: "Bad ID" }), /id/],
        [learningRecord({ scope: [] }), /scope/],
        [learningRecord({ scope: "docs" }), /scope/],
        [learningRecord({ scope: [42] }), /scope/],
        [learningRecord({ scope: ["/docs"] }), /scope/],
        [learningRecord({ scope: ["../outside"] }), /scope/],
        [learningRecord({ learning: " " }), /learning/],
        [learningRecord({ evidence: [] }), /evidence/],
        [learningRecord({ evidence: [42] }), /evidence/],
        [learningRecord({ status: "done" }), /status/],
        [learningRecord({ destination: "https://example.com" }), /destination/],
        [learningRecord({ destination: "../outside" }), /destination/],
        [learningRecord({ destination: "docs/missing.md" }), /destination/],
        [learningRecord({ destination: "docs" }), /destination/],
        [learningRecord({ destination: 42 }), /destination/],
        [learningRecord({ validation: "passed" }), /validation/],
        [
            learningRecord({ validation: { confidence: "unknown", checks: [], delivery: null } }),
            /validation/,
        ],
        [
            learningRecord({ validation: { confidence: "proven", checks: [42], delivery: null } }),
            /validation/,
        ],
        [learningRecord({ extra: "unversioned field" }), /fields/],
    ];
    for (const [record, expected] of invalidRecords) {
        assert.match(validateLearningRecords(root, JSON.stringify(record)).join("\n"), expected);
    }
});

test("learning states distinguish proposed, locally proven and delivered outcomes", (context) => {
    const root = fixture();
    context.after(() => rmSync(root, { recursive: true, force: true }));
    for (const status of ["candidate", "discarded", "superseded"]) {
        const record = learningRecord({ status, destination: null });
        assert.deepEqual(validateLearningRecords(root, JSON.stringify(record)), []);
    }
    for (const status of ["pending_approval", "applied_locally", "integrated"]) {
        const missingOwner = learningRecord({ status, destination: null });
        assert.match(
            validateLearningRecords(root, JSON.stringify(missingOwner)).join("\n"),
            /destination/,
        );
    }
    for (const status of ["applied_locally", "integrated"]) {
        const record = learningRecord({ status });
        assert.match(validateLearningRecords(root, JSON.stringify(record)).join("\n"), /proven/);
    }
    const validation = {
        confidence: "proven",
        checks: ["Synthetic passing check."],
        delivery: null,
    };
    const local = learningRecord({ status: "applied_locally", validation });
    assert.deepEqual(validateLearningRecords(root, JSON.stringify(local)), []);

    const integrated = learningRecord({ status: "integrated", validation });
    assert.match(validateLearningRecords(root, JSON.stringify(integrated)).join("\n"), /delivery/);
    validation.delivery = "Synthetic merged revision and exact-main CI evidence.";
    assert.deepEqual(validateLearningRecords(root, JSON.stringify(integrated)), []);

    const pending = learningRecord({ status: "pending_approval" });
    assert.deepEqual(validateLearningRecords(root, JSON.stringify(pending)), []);
    const anchored = learningRecord({ destination: "docs/requirements.md#requirement-register" });
    assert.deepEqual(validateLearningRecords(root, JSON.stringify(anchored)), []);
});

test("repository validation includes the portable learning registry", (context) => {
    const root = fixture();
    context.after(() => rmSync(root, { recursive: true, force: true }));
    mkdirSync(join(root, "skills/fidelitopass-continuous-improvement"), { recursive: true });
    const path = join(root, "skills/fidelitopass-continuous-improvement/learning.jsonl");
    writeFileSync(path, "");
    assert.deepEqual(validateRepository(root), []);
    writeFileSync(path, JSON.stringify(learningRecord({ evidence: [] })));
    assert.match(validateRepository(root).join("\n"), /learning.jsonl.*evidence/);
});

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

test("register and headings must have unique matching identities", (context) => {
    const root = fixture();
    context.after(() => rmSync(root, { recursive: true, force: true }));
    const path = join(root, "docs/requirements.md");
    const rows = Array.from(
        { length: 35 },
        (_, i) => `| ${i + 1} | REQ-TST-${String(i + 1).padStart(3, "0")} | Must |`,
    );
    const headings = Array.from(
        { length: 35 },
        (_, i) => `#### REQ-TST-${String(i + 1).padStart(3, "0")} — Entry`,
    );
    const source = `- Total requirements: **35**.\n## Requirement register\n${rows.join("\n")}\n## Detailed requirements\n${headings.join("\n")}\n`;
    writeFileSync(path, source);
    assert.deepEqual(validateRequirementIds(root), []);
    writeFileSync(join(root, "README.md"), "REQ-TST-999 REQ-TST--001");
    assert.match(validateRequirementIds(root).join("\n"), /unknown requirement/);
    assert.match(validateRequirementIds(root).join("\n"), /malformed requirement/);
    writeFileSync(join(root, "README.md"), "");
    writeFileSync(path, source.replace(headings[1], headings[0]));
    assert.match(validateRequirementIds(root).join("\n"), /more than once/);
    writeFileSync(path, source.replace(rows[1], rows[0]));
    assert.match(validateRequirementIds(root).join("\n"), /repeats register row/);
    writeFileSync(path, source.replace(rows[1], ""));
    assert.match(validateRequirementIds(root).join("\n"), /has no register row/);
    writeFileSync(
        path,
        source.replace(`${headings[1]}\n${headings[2]}`, `${headings[2]}\n${headings[1]}`),
    );
    assert.match(validateRequirementIds(root).join("\n"), /must match in order/);
    writeFileSync(path, source.replace(`${rows[1]}\n`, "").replace(`${headings[1]}\n`, ""));
    assert.match(validateRequirementIds(root).join("\n"), /total requirements.*register count/i);
    writeFileSync(path, source.replace("- Total requirements: **35**.\n", ""));
    assert.match(validateRequirementIds(root).join("\n"), /total requirements.*missing/i);
    writeFileSync(path, source.replace("**35**", "**36**"));
    assert.match(validateRequirementIds(root).join("\n"), /total requirements.*register count/i);
    writeFileSync(
        path,
        source
            .replace("**35**", "**36**")
            .replace(
                "## Detailed requirements",
                `| 36 | REQ-TST-036 | Must |\n## Detailed requirements`,
            )
            .concat("#### REQ-TST-036 — Entry\n"),
    );
    assert.deepEqual(validateRequirementIds(root), []);
});
