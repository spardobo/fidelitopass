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

test("awaits the clipboard API and gives a short success toast for every completed copy", async () => {
    const url = "https://acquire.example.test/join/01900000-0000-7000-8000-000000000001";
    const writes = [];
    const toasts = [];
    let complete;
    const pending = new Promise((resolve) => {
        complete = resolve;
    });
    const scope = createScope();
    scope.$el = { isConnected: true, dataset: {} };
    scope.$root = { dataset: { copiedMessage: "Enlace copiado." } };
    scope.$flux = { toast: (text, options) => toasts.push({ text, ...options }) };
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
    assert.deepEqual(toasts, []);
    await scope.copy();
    assert.deepEqual(writes, [url]);

    complete();
    await copying;

    assert.equal(scope.copying, false);
    assert.equal(scope.copyFailed, false);
    assert.deepEqual(toasts, [{ text: "Enlace copiado.", variant: "success", duration: 2000 }]);

    await scope.copy();
    assert.deepEqual(writes, [url, url]);
    assert.equal(toasts.length, 2);
    assert.deepEqual(toasts[1], toasts[0]);

    const leaving = scope.copy();
    scope.$el.isConnected = false;
    await leaving;
    assert.equal(toasts.length, 2);
});

test("retains manual-copy guidance while a subsequent copy is pending", async () => {
    let complete;
    const pending = new Promise((resolve) => {
        complete = resolve;
    });
    const scope = createScope();
    scope.copyFailed = true;
    scope.$el = { isConnected: true, dataset: {} };
    scope.$root = { dataset: { copiedMessage: "Enlace copiado." } };
    scope.$flux = { toast: () => {} };
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

test("confirms selection fallback only on success and keeps manual selection on failure", async () => {
    for (const copied of [true, false]) {
        const toasts = [];
        const focus = [];
        const url = "https://acquire.example.test/join/public-business";
        const scope = createScope();
        scope.$el = { isConnected: true, dataset: {} };
        scope.$root = { dataset: { copiedMessage: "Enlace copiado." } };
        scope.$flux = { toast: (text, options) => toasts.push({ text, ...options }) };
        scope.$refs = {
            url: {
                value: url,
                isConnected: true,
                focus: () => focus.push("input"),
                select: () => focus.push("selection"),
                setSelectionRange: (start, end) => assert.deepEqual([start, end], [0, url.length]),
                ownerDocument: {
                    defaultView: {
                        navigator: { clipboard: { writeText: () => Promise.reject() } },
                    },
                    activeElement: { focus: () => focus.push("previous") },
                    execCommand: (command) => {
                        assert.equal(command, "copy");
                        return copied;
                    },
                },
            },
        };

        await scope.copy();

        assert.equal(scope.copying, false);
        assert.equal(scope.copyFailed, !copied);
        assert.deepEqual(
            toasts,
            copied ? [{ text: "Enlace copiado.", variant: "success", duration: 2000 }] : [],
        );
        assert.deepEqual(
            focus,
            copied ? ["input", "selection", "previous"] : ["input", "selection"],
        );
    }
});
