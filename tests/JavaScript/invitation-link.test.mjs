import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import vm from "node:vm";

const source = readFileSync(
    new URL("../../resources/views/pages/business/⚡invitation/invitation.js", import.meta.url),
    "utf8",
);
let createScope;
vm.runInNewContext(source, {
    Alpine: {
        data: (_name, factory) => {
            createScope = factory;
        },
    },
});

test("confirms invitation copying only after the supported clipboard API completes", async () => {
    const url = "https://acquire.example.test/join/01900000-0000-7000-8000-000000000001";
    const writes = [];
    let complete;
    const pending = new Promise((resolve) => {
        complete = resolve;
    });
    const scope = createScope();
    scope.$el = { isConnected: true };
    scope.$refs = {
        url: {
            value: url,
            ownerDocument: {
                defaultView: {
                    navigator: {
                        clipboard: {
                            writeText: (value) => {
                                writes.push(value);
                                return pending;
                            },
                        },
                    },
                },
                execCommand: () => assert.fail("a successful clipboard API needs no fallback"),
            },
        },
    };

    const copying = scope.copy();
    assert.deepEqual(writes, [url]);
    assert.equal(scope.copying, true);
    assert.equal(scope.copied, false);
    assert.equal(scope.copyFailed, false);

    complete();
    await copying;

    assert.equal(scope.copying, false);
    assert.equal(scope.copied, true);
    assert.equal(scope.copyFailed, false);
});

for (const previous of [
    { label: "successful", copied: true, copyFailed: false },
    { label: "manual-copy", copied: false, copyFailed: true },
]) {
    test(`retains settled ${previous.label} feedback while a subsequent copy is pending`, async () => {
        let complete;
        const pending = new Promise((resolve) => {
            complete = resolve;
        });
        const scope = createScope();
        scope.copied = previous.copied;
        scope.copyFailed = previous.copyFailed;
        scope.$el = { isConnected: true };
        scope.$refs = {
            url: {
                value: "https://acquire.example.test/join/public-business",
                ownerDocument: {
                    defaultView: { navigator: { clipboard: { writeText: () => pending } } },
                },
            },
        };

        const copying = scope.copy();

        assert.equal(scope.copying, true);
        assert.equal(scope.copied, previous.copied);
        assert.equal(scope.copyFailed, previous.copyFailed);
        complete();
        await copying;
        assert.equal(scope.copying, false);
        assert.equal(scope.copied, true);
        assert.equal(scope.copyFailed, false);
    });
}
