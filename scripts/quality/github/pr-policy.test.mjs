import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import vm from "node:vm";

const workflow = readFileSync(
    new URL("../../../.github/workflows/pr-policy.yml", import.meta.url),
    "utf8",
);
const script = workflow.split("          script: |\n")[1].replace(/^ {12}/gm, "");

/**
 * Executes the real github-script policy against queued and current API metadata.
 *
 * @param {object} options Event and read-only GitHub API metadata.
 * @param {string[]} options.eventLabels Labels captured by the original event.
 * @param {string[]} options.currentLabels Labels returned by the current pull-request read.
 * @param {string} [options.actor] Pull-request author login.
 * @param {string} [options.branch] Head branch checked by the existing naming policy.
 * @param {string} [options.body] Event body containing native issue references.
 * @param {boolean} [options.approved] Whether the referenced issue is approved.
 * @param {Error} [options.readError] Failure of the current pull-request API read.
 * @returns {Promise<object>} Public policy messages and exact metadata requests.
 */
async function runPolicy({
    eventLabels,
    currentLabels,
    actor = "dependabot[bot]",
    branch = "dependabot/npm_and_yarn/patches",
    body = "",
    approved = true,
    readError,
}) {
    const failures = [];
    const messages = [];
    const reads = [];
    const context = {
        repo: { owner: "octo", repo: "example" },
        payload: {
            pull_request: {
                number: 7,
                head: { ref: branch },
                user: { login: actor },
                labels: eventLabels.map((name) => ({ name })),
                body,
            },
        },
    };
    const github = {
        rest: {
            pulls: {
                get: async (parameters) => {
                    if (readError) {
                        throw readError;
                    }
                    reads.push({ ...parameters });
                    return { data: { labels: currentLabels.map((name) => ({ name })) } };
                },
            },
            issues: {
                get: async (parameters) => {
                    reads.push({ ...parameters });
                    return {
                        data: { labels: approved ? [{ name: "status:approved" }] : [] },
                    };
                },
            },
        },
    };
    const core = {
        setFailed: (message) => failures.push(message),
        info: (message) => messages.push(message),
    };

    await vm.runInNewContext(`(async () => {\n${script}\n})()`, { context, github, core });

    return { failures, messages, reads };
}

test("validates current Dependabot labels instead of an empty queued event snapshot", async () => {
    const result = await runPolicy({ eventLabels: [], currentLabels: ["type:chore"] });

    assert.deepEqual(result.failures, []);
    assert.deepEqual(result.messages, [
        "Dependabot maintenance policy passed with label type:chore.",
    ]);
    assert.deepEqual(result.reads, [{ owner: "octo", repo: "example", pull_number: 7 }]);
});

for (const { labels, count } of [
    { labels: [], count: 0 },
    { labels: ["type:chore", "type:bug"], count: 2 },
]) {
    test(`rejects ${count} current type labels despite a valid old event label`, async () => {
        const result = await runPolicy({ eventLabels: ["type:chore"], currentLabels: labels });

        assert.deepEqual(result.failures, [`Expected exactly one type:* label; found ${count}.`]);
        assert.deepEqual(result.messages, []);
        assert.deepEqual(result.reads, [{ owner: "octo", repo: "example", pull_number: 7 }]);
    });
}

test("keeps the Dependabot type:chore restriction", async () => {
    const result = await runPolicy({ eventLabels: ["type:bug"], currentLabels: ["type:bug"] });

    assert.deepEqual(result.failures, [
        "Dependabot pull requests must have exactly the type:chore label.",
    ]);
    assert.deepEqual(result.messages, []);
});

test("preserves approved-issue validation for human pull requests", async () => {
    const result = await runPolicy({
        eventLabels: ["type:bug"],
        currentLabels: ["type:bug"],
        actor: "owner",
        branch: "fix/example",
        body: "Closes #77",
    });

    assert.deepEqual(result.failures, []);
    assert.deepEqual(result.messages, [
        "Policy passed with approved issue #77 and label type:bug.",
    ]);
    assert.deepEqual(result.reads, [
        { owner: "octo", repo: "example", pull_number: 7 },
        { owner: "octo", repo: "example", issue_number: 77 },
    ]);
});

test("still rejects an unapproved human issue", async () => {
    const result = await runPolicy({
        eventLabels: ["type:bug"],
        currentLabels: ["type:bug"],
        actor: "owner",
        branch: "fix/example",
        body: "Refs #77",
        approved: false,
    });

    assert.deepEqual(result.failures, [
        "At least one referenced issue must exist in this repository and have status:approved.",
    ]);
    assert.deepEqual(result.messages, []);
});

test("keeps invalid branch rejection before metadata reads", async () => {
    const result = await runPolicy({
        eventLabels: ["type:chore"],
        currentLabels: ["type:chore"],
        branch: "invalid",
    });

    assert.deepEqual(result.failures, [
        'Branch "invalid" does not follow the documented naming convention.',
    ]);
    assert.deepEqual(result.messages, []);
    assert.deepEqual(result.reads, []);
});

test("fails closed when current labels cannot be read", async () => {
    const error = new Error("metadata read unavailable");

    await assert.rejects(
        runPolicy({ eventLabels: ["type:chore"], currentLabels: ["type:chore"], readError: error }),
        error,
    );
});
