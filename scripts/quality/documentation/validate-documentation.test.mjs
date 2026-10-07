import assert from "node:assert/strict";
import { mkdirSync, mkdtempSync, rmSync, symlinkSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { test } from "node:test";

import {
    validateLearningRecords,
    validateMarkdownLinks,
    validateRepository,
    validateRequirementIds,
} from "./validate-documentation.mjs";

const REQUIREMENTS_PATH = "docs/requirements.md";

const LEARNING_REGISTRY_PATH = "skills/fidelitopass-continuous-improvement/learning.jsonl";

const DEFAULT_REQUIREMENT = "REQ-TEC-001";

const REQUIREMENT_COUNT = 35;

function cleanupFixture(context, root) {
    context.after(() => {
        rmSync(root, {
            recursive: true,
            force: true,
        });
    });
}

// synthetic records exercise mechanics only;
// they are not project learnings.
function learningRecord(overrides = {}) {
    return {
        id: "synthetic-correction",
        scope: ["docs"],
        learning: "Synthetic reusable correction for validator tests.",
        evidence: ["Synthetic observed check."],
        destination: REQUIREMENTS_PATH,
        status: "candidate",
        validation: {
            confidence: "provisional",
            checks: ["Synthetic check."],
            delivery: null,
        },
        ...overrides,
    };
}

function fixture() {
    const root = mkdtempSync(join(tmpdir(), "fidelitopass-quality-"));

    mkdirSync(join(root, "docs"));

    writeFileSync(
        join(root, REQUIREMENTS_PATH),
        [
            "- Total requirements: **1**.",
            "## Requirement register",
            `| 1 | ${DEFAULT_REQUIREMENT} | Must |`,
            "## Detailed requirements",
            `#### ${DEFAULT_REQUIREMENT} — Provide a valid requirement`,
            "",
        ].join("\n"),
    );

    writeFileSync(join(root, "README.md"), "[Requirements](docs/requirements.md)\n");

    return root;
}

function createFixture(context) {
    const root = fixture();

    cleanupFixture(context, root);

    return root;
}

function validateLearning(root, record) {
    return validateLearningRecords(root, JSON.stringify(record));
}

function learningErrors(root, record) {
    return validateLearning(root, record).join("\n");
}

function requirementId(index) {
    return `REQ-TST-${String(index).padStart(3, "0")}`;
}

function requirementDocument() {
    const rows = Array.from(
        {
            length: REQUIREMENT_COUNT,
        },
        (_, index) => `| ${index + 1} | ${requirementId(index + 1)} | Must |`,
    );

    const headings = Array.from(
        {
            length: REQUIREMENT_COUNT,
        },
        (_, index) => `#### ${requirementId(index + 1)} — Entry`,
    );

    const source = [
        `- Total requirements: **${REQUIREMENT_COUNT}**.`,
        "## Requirement register",
        ...rows,
        "## Detailed requirements",
        ...headings,
        "",
    ].join("\n");

    return {
        rows,
        headings,
        source,
    };
}

test("learning registry accepts empty input and one current entry per stable identity", (context) => {
    const root = createFixture(context);

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
    const root = createFixture(context);

    const invalidRecords = [
        [
            learningRecord({
                id: "Bad ID",
            }),
            /id/,
        ],
        [
            learningRecord({
                scope: [],
            }),
            /scope/,
        ],
        [
            learningRecord({
                scope: "docs",
            }),
            /scope/,
        ],
        [
            learningRecord({
                scope: [42],
            }),
            /scope/,
        ],
        [
            learningRecord({
                scope: ["/docs"],
            }),
            /scope/,
        ],
        [
            learningRecord({
                scope: ["../outside"],
            }),
            /scope/,
        ],
        [
            learningRecord({
                learning: " ",
            }),
            /learning/,
        ],
        [
            learningRecord({
                evidence: [],
            }),
            /evidence/,
        ],
        [
            learningRecord({
                evidence: [42],
            }),
            /evidence/,
        ],
        [
            learningRecord({
                status: "done",
            }),
            /status/,
        ],
        [
            learningRecord({
                destination: "https://example.com",
            }),
            /destination/,
        ],
        [
            learningRecord({
                destination: "../outside",
            }),
            /destination/,
        ],
        [
            learningRecord({
                destination: "docs/missing.md",
            }),
            /destination/,
        ],
        [
            learningRecord({
                destination: "docs",
            }),
            /destination/,
        ],
        [
            learningRecord({
                destination: 42,
            }),
            /destination/,
        ],
        [
            learningRecord({
                validation: "passed",
            }),
            /validation/,
        ],
        [
            learningRecord({
                validation: {
                    confidence: "unknown",
                    checks: [],
                    delivery: null,
                },
            }),
            /validation/,
        ],
        [
            learningRecord({
                validation: {
                    confidence: "proven",
                    checks: [42],
                    delivery: null,
                },
            }),
            /validation/,
        ],
        [
            learningRecord({
                extra: "unversioned field",
            }),
            /fields/,
        ],
    ];

    for (const [record, expected] of invalidRecords) {
        assert.match(learningErrors(root, record), expected);
    }
});

test("learning paths retain lexical rules and file/directory roles", (context) => {
    const root = createFixture(context);

    const valid = learningRecord({ scope: ["docs", "docs/requirements.md"] });
    assert.deepEqual(validateLearning(root, valid), []);

    for (const path of [
        ".",
        "docs/../docs",
        "docs/./requirements.md",
        "docs//requirements.md",
        "docs/",
        "docs#heading",
        "missing",
        "C:/docs",
        "docs\\requirements.md",
    ]) {
        assert.match(learningErrors(root, learningRecord({ scope: [path] })), /scope/);
    }

    for (const destination of ["docs", "docs/missing.md", "../outside", "/docs/requirements.md"]) {
        assert.match(learningErrors(root, learningRecord({ destination })), /destination/);
    }
});

test("learning paths accept internal file and directory symlinks and a linked root", (context) => {
    const root = createFixture(context);
    const workspace = mkdtempSync(join(tmpdir(), "fidelitopass-links-"));
    cleanupFixture(context, workspace);

    symlinkSync(join(root, "docs/requirements.md"), join(root, "owner.md"));
    symlinkSync(join(root, "docs"), join(root, "owners"));
    const linkedRoot = join(workspace, "repository");
    symlinkSync(root, linkedRoot);
    const record = learningRecord({
        scope: ["owner.md", "owners"],
        destination: "owners/requirements.md#requirement-register",
    });

    for (const repository of [root, linkedRoot]) {
        assert.deepEqual(validateLearning(repository, record), []);
    }
});

test("learning paths reject external direct and intermediate symlinks including similar prefixes", (context) => {
    const root = createFixture(context);
    const outside = `${root}-outside`;
    mkdirSync(outside);
    cleanupFixture(context, outside);

    writeFileSync(join(outside, "owner.md"), "Controlled external owner fixture.\n");
    symlinkSync(join(outside, "owner.md"), join(root, "external.md"));
    symlinkSync(outside, join(root, "external"));

    for (const path of ["external.md", "external", "external/owner.md"]) {
        assert.match(learningErrors(root, learningRecord({ scope: [path] })), /scope/);
    }

    for (const destination of ["external.md", "external/owner.md#heading"]) {
        assert.match(learningErrors(root, learningRecord({ destination })), /destination/);
    }
});

test("learning paths reject dangling and looping symlinks without throwing", (context) => {
    const root = createFixture(context);

    symlinkSync(join(root, "missing"), join(root, "broken"));
    symlinkSync("loop", join(root, "loop"));

    for (const path of ["broken", "broken/owner.md", "loop", "loop/owner.md"]) {
        const record = learningRecord({ scope: [path], destination: path });
        const errors = learningErrors(root, record);
        assert.match(errors, /scope/);
        assert.match(errors, /destination/);
    }
});

test("repository validation rejects external learning registry before reading valid JSONL", (context) => {
    const root = createFixture(context);
    const outside = mkdtempSync(join(tmpdir(), "fidelitopass-registry-"));
    cleanupFixture(context, outside);

    const registryDirectory = join(root, "skills/fidelitopass-continuous-improvement");
    mkdirSync(registryDirectory, { recursive: true });
    const content = JSON.stringify(learningRecord());
    assert.deepEqual(validateLearningRecords(root, content), []);
    writeFileSync(join(outside, "learning.jsonl"), content);
    symlinkSync(join(outside, "learning.jsonl"), join(registryDirectory, "learning.jsonl"));

    assert.match(validateRepository(root).join("\n"), /learning\.jsonl.*repository.*file/);
});

test("repository validation rejects intermediate external and broken registry symlinks", (context) => {
    const root = createFixture(context);
    const outside = mkdtempSync(join(tmpdir(), "fidelitopass-registry-"));
    cleanupFixture(context, outside);

    mkdirSync(join(root, "skills"));
    writeFileSync(join(outside, "learning.jsonl"), JSON.stringify(learningRecord()));
    const ownerDirectory = join(root, "skills/fidelitopass-continuous-improvement");
    symlinkSync(outside, ownerDirectory);
    assert.match(validateRepository(root).join("\n"), /learning\.jsonl.*repository.*file/);

    rmSync(join(outside, "learning.jsonl"));
    assert.match(validateRepository(root).join("\n"), /learning\.jsonl.*repository.*file/);

    rmSync(ownerDirectory);
    symlinkSync(join(root, "missing"), ownerDirectory);
    assert.match(validateRepository(root).join("\n"), /learning\.jsonl.*repository.*file/);

    rmSync(ownerDirectory);
    mkdirSync(ownerDirectory);
    const registry = join(ownerDirectory, "learning.jsonl");
    symlinkSync(join(root, "missing.jsonl"), registry);
    assert.match(validateRepository(root).join("\n"), /learning\.jsonl.*repository.*file/);

    rmSync(registry);
    symlinkSync("learning.jsonl", registry);
    assert.match(validateRepository(root).join("\n"), /learning\.jsonl.*repository.*file/);

    rmSync(registry);
    mkdirSync(registry);
    assert.match(validateRepository(root).join("\n"), /learning\.jsonl.*repository.*file/);

    rmSync(registry, { recursive: true });
    assert.deepEqual(validateRepository(root), []);

    rmSync(ownerDirectory, { recursive: true });
    writeFileSync(ownerDirectory, "A file cannot contain the optional registry.\n");
    assert.match(validateRepository(root).join("\n"), /learning\.jsonl.*repository.*file/);
});

test("repository validation accepts an internal registry symlink and linked root", (context) => {
    const root = createFixture(context);
    const workspace = mkdtempSync(join(tmpdir(), "fidelitopass-registry-"));
    cleanupFixture(context, workspace);

    mkdirSync(join(root, "skills/fidelitopass-continuous-improvement"), { recursive: true });
    writeFileSync(join(root, "records.jsonl"), JSON.stringify(learningRecord()));
    symlinkSync(
        join(root, "records.jsonl"),
        join(root, "skills/fidelitopass-continuous-improvement/learning.jsonl"),
    );
    const linkedRoot = join(workspace, "repository");
    symlinkSync(root, linkedRoot);

    assert.deepEqual(validateRepository(root), []);
    assert.deepEqual(validateRepository(linkedRoot), []);
});

test("learning states distinguish proposed, locally proven and delivered outcomes", (context) => {
    const root = createFixture(context);

    for (const status of ["candidate", "discarded", "superseded"]) {
        const record = learningRecord({
            status,
            destination: null,
        });

        assert.deepEqual(validateLearning(root, record), []);
    }

    for (const status of ["pending_approval", "applied_locally", "integrated"]) {
        const missingOwner = learningRecord({
            status,
            destination: null,
        });

        assert.match(learningErrors(root, missingOwner), /destination/);
    }

    for (const status of ["applied_locally", "integrated"]) {
        const record = learningRecord({
            status,
        });

        assert.match(learningErrors(root, record), /proven/);
    }

    const validation = {
        confidence: "proven",
        checks: ["Synthetic passing check."],
        delivery: null,
    };

    const local = learningRecord({
        status: "applied_locally",
        validation,
    });

    assert.deepEqual(validateLearning(root, local), []);

    const integrated = learningRecord({
        status: "integrated",
        validation,
    });

    assert.match(learningErrors(root, integrated), /delivery/);

    validation.delivery = "Synthetic merged revision and exact-main CI evidence.";

    assert.deepEqual(validateLearning(root, integrated), []);

    const pending = learningRecord({
        status: "pending_approval",
    });

    assert.deepEqual(validateLearning(root, pending), []);

    const anchored = learningRecord({
        destination: "docs/requirements.md#requirement-register",
    });

    assert.deepEqual(validateLearning(root, anchored), []);
});

test("repository validation includes the portable learning registry", (context) => {
    const root = createFixture(context);

    mkdirSync(join(root, "skills/fidelitopass-continuous-improvement"), {
        recursive: true,
    });

    const path = join(root, LEARNING_REGISTRY_PATH);

    writeFileSync(path, "");

    assert.deepEqual(validateRepository(root), []);

    writeFileSync(
        path,
        JSON.stringify(
            learningRecord({
                evidence: [],
            }),
        ),
    );

    assert.match(validateRepository(root).join("\n"), /learning.jsonl.*evidence/);
});

test("relative Markdown links resolve from their source file", (context) => {
    const root = createFixture(context);

    assert.deepEqual(validateMarkdownLinks(root), []);

    writeFileSync(join(root, "README.md"), "[Missing](docs/missing.md)\n");

    assert.match(validateMarkdownLinks(root)[0], /references missing path/);
});

test("skill Markdown links and local inline paths resolve", (context) => {
    const root = createFixture(context);

    mkdirSync(join(root, "skills/example/references"), {
        recursive: true,
    });

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
    const root = createFixture(context);

    writeFileSync(join(root, "README.md"), "Read `docs/missing.md` and `docs/requirements.md`.\n");

    assert.match(validateMarkdownLinks(root)[0], /docs\/missing\.md/);

    writeFileSync(
        join(root, "README.md"),
        [
            "`docs/requirements.md` [External](https://example.com/no) [Anchor](#missing)",
            "```sh",
            "cat `docs/not-a-reference.md`",
            "```",
            "",
        ].join("\n"),
    );

    assert.deepEqual(validateMarkdownLinks(root), []);
});

test("requirements accept 35 unique register rows with matching ordered headings", (context) => {
    const root = createFixture(context);

    writeFileSync(join(root, REQUIREMENTS_PATH), requirementDocument().source);

    assert.deepEqual(validateRequirementIds(root), []);
});

for (const [name, reference, expected] of [
    ["unknown requirement references are rejected", "REQ-TST-999", /unknown requirement/],
    ["malformed requirement identifiers are rejected", "REQ-TST--001", /malformed requirement/],
]) {
    test(name, (context) => {
        const root = createFixture(context);

        writeFileSync(join(root, REQUIREMENTS_PATH), requirementDocument().source);

        writeFileSync(join(root, "README.md"), reference);

        assert.match(validateRequirementIds(root).join("\n"), expected);
    });
}

for (const [name, document, expected] of [
    [
        "duplicate requirement headings are rejected",
        ({ source, headings }) => source.replace(headings[1], headings[0]),
        /more than once/,
    ],
    [
        "duplicate requirement register rows are rejected",
        ({ source, rows }) => source.replace(rows[1], rows[0]),
        /repeats register row/,
    ],
    [
        "requirement headings without a register row are rejected",
        ({ source, rows }) => source.replace(rows[1], ""),
        /has no register row/,
    ],
    [
        "requirement register rows without a heading are rejected",
        ({ source, headings }) => source.replace(headings[1], ""),
        /has no heading/,
    ],
    [
        "requirement headings out of register order are rejected",
        ({ source, headings }) =>
            source.replace(`${headings[1]}\n${headings[2]}`, `${headings[2]}\n${headings[1]}`),
        /must match in order/,
    ],
    [
        "removing a matching row and heading requires updating the declared total",
        ({ source, rows, headings }) =>
            source.replace(`${rows[1]}\n`, "").replace(`${headings[1]}\n`, ""),
        /total requirements.*register count/i,
    ],
    [
        "requirements without a declared total are rejected",
        ({ source }) => source.replace(`- Total requirements: **${REQUIREMENT_COUNT}**.\n`, ""),
        /total requirements.*missing/i,
    ],
    [
        "a declared requirement total that differs from the register count is rejected",
        ({ source }) => source.replace(`**${REQUIREMENT_COUNT}**`, `**${REQUIREMENT_COUNT + 1}**`),
        /total requirements.*register count/i,
    ],
]) {
    test(name, (context) => {
        const root = createFixture(context);

        writeFileSync(join(root, REQUIREMENTS_PATH), document(requirementDocument()));

        assert.match(validateRequirementIds(root).join("\n"), expected);
    });
}

test("requirements accept a 36th matching row and heading when the declared total is updated", (context) => {
    const root = createFixture(context);

    const { source } = requirementDocument();

    const nextCount = REQUIREMENT_COUNT + 1;

    const nextId = requirementId(nextCount);

    writeFileSync(
        join(root, REQUIREMENTS_PATH),
        source
            .replace(`**${REQUIREMENT_COUNT}**`, `**${nextCount}**`)
            .replace(
                "## Detailed requirements",
                `| ${nextCount} | ${nextId} | Must |\n## Detailed requirements`,
            )
            .concat(`#### ${nextId} — Entry\n`),
    );

    assert.deepEqual(validateRequirementIds(root), []);
});
