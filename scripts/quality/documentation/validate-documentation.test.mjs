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
        "- Total requirements: **1**.\n## Requirement register\n| 1 | REQ-TEC-001 | Must |\n## Detailed requirements\n#### REQ-TEC-001 — Provide a valid requirement\n",
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
