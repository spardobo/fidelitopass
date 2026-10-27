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

test("awaits the supported invitation clipboard API before releasing the copy control", async () => {
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
    assert.equal(scope.copyFailed, false);

    complete();
    await copying;

    assert.equal(scope.copying, false);
    assert.equal(scope.copyFailed, false);
});

test("retains manual-copy guidance while a subsequent copy is pending", async () => {
    let complete;
    const pending = new Promise((resolve) => {
        complete = resolve;
    });
    const scope = createScope();
    scope.copyFailed = true;
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
    assert.equal(scope.copyFailed, true);
    complete();
    await copying;
    assert.equal(scope.copying, false);
    assert.equal(scope.copyFailed, false);
});
