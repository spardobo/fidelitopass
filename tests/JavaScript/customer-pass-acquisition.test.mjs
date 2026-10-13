import assert from "node:assert/strict";
import { test } from "node:test";
import { customerPassAcquisition } from "../../resources/js/customer-pass-acquisition.js";

const handoff = {
    save_url:
        "https://pay.google.com/gp/v/save/eyJhbGciOiJSUzI1NiJ9.eyJ0eXAiOiJzYXZldG93YWxsZXQifQ.signature",
    manual_code: "00007",
};

/** Creates a JSON response at the private HTTP boundary.
 * @param {number} status Observed HTTP status.
 * @param {object} body Private response or sanitized failure.
 * @returns {Response} Native JSON response used by the client.
 */
function response(status, body) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { "Content-Type": "application/json" },
    });
}

test("submits once with CSRF, suppresses double clicks and retains only the confirmed handoff", async () => {
    let complete;
    const calls = [];
    const client = customerPassAcquisition("/join/business/save", "csrf", (url, options) => {
        calls.push({ url, options });
        return new Promise((resolve) => {
            complete = resolve;
        });
    });

    const pending = client.save();
    assert.equal(client.state, "loading");
    await client.save();
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, "/join/business/save");
    assert.equal(calls[0].options.method, "POST");
    assert.equal(calls[0].options.headers["X-CSRF-TOKEN"], "csrf");
    assert.equal(calls[0].options.credentials, "same-origin");
    assert.equal(calls[0].options.cache, "no-store");
    complete(response(201, handoff));
    await pending;

    assert.equal(client.state, "ready");
    assert.equal(client.saveUrl, handoff.save_url);
    assert.equal(client.manualCode, handoff.manual_code);
    await client.save();
    assert.equal(calls.length, 1);
});

test("allows a new explicit attempt only after a classified safe definitive failure", async () => {
    let calls = 0;
    const client = customerPassAcquisition("/join/business/save", "csrf", async () => {
        calls++;
        return calls === 1
            ? response(502, { outcome: "definitive", stage: "provider", retry_allowed: true })
            : response(201, handoff);
    });

    await client.save();
    assert.equal(client.state, "retryable");
    assert.equal(client.saveUrl, "");
    await client.save();
    assert.equal(client.state, "ready");
    assert.equal(calls, 2);
});

for (const [name, request] of [
    [
        "unknown provider",
        async () => response(503, { outcome: "unknown", stage: "provider", retry_allowed: false }),
    ],
    [
        "unknown local commit",
        async () => response(503, { outcome: "unknown", stage: "local", retry_allowed: false }),
    ],
    [
        "unsafe definitive",
        async () =>
            response(503, { outcome: "definitive", stage: "preparation", retry_allowed: false }),
    ],
    [
        "forged retry permission",
        async () => response(503, { outcome: "unknown", stage: "provider", retry_allowed: true }),
    ],
    [
        "transport loss",
        async () => {
            throw new Error("private transport details");
        },
    ],
    ["non-JSON", async () => new Response("private response", { status: 503 })],
    ["redirect", async () => ({ redirected: true, status: 201, json: async () => handoff })],
    ["unclassified HTTP rejection", async () => response(419, {})],
    ["wrong success status", async () => response(200, handoff)],
    [
        "unsafe handoff origin",
        async () =>
            response(201, {
                ...handoff,
                save_url: "https://pay.google.com.attacker.test/gp/v/save/a.b.c",
            }),
    ],
    [
        "unsafe handoff path",
        async () =>
            response(201, {
                ...handoff,
                save_url: "https://pay.google.com/gp/v/save/a.b.c?redirect=evil",
            }),
    ],
    [
        "unsafe handoff credentials",
        async () =>
            response(201, { ...handoff, save_url: "https://user@pay.google.com/gp/v/save/a.b.c" }),
    ],
    ["invalid code", async () => response(201, { ...handoff, manual_code: "<script>" })],
    ["missing code", async () => response(201, { save_url: handoff.save_url })],
]) {
    test(`blocks blind reissuance after ${name}`, async () => {
        let calls = 0;
        const client = customerPassAcquisition("/join/business/save", "csrf", (...args) => {
            calls++;
            return request(...args);
        });
        await client.save();
        assert.equal(client.state, "blocked");
        assert.equal(client.manualCode, "");
        assert.equal(client.saveUrl, "");
        await client.save();
        assert.equal(calls, 1);
    });
}

test("disposal clears private handoff and prevents an in-flight response from republishing it", async () => {
    let complete;
    const client = customerPassAcquisition(
        "/join/business/save",
        "csrf",
        () =>
            new Promise((resolve) => {
                complete = resolve;
            }),
    );
    const pending = client.save();
    client.destroy();
    complete(response(201, handoff));
    await pending;
    assert.equal(client.saveUrl, "");
    assert.equal(client.manualCode, "");
    assert.equal(client.state, "blocked");
});

test("disposal releases a completed private handoff and cannot start another attempt", async () => {
    let calls = 0;
    const client = customerPassAcquisition("/join/business/save", "csrf", async () => {
        calls++;
        return response(201, handoff);
    });
    await client.save();
    assert.equal(client.saveUrl, handoff.save_url);
    client.destroy();
    assert.equal(client.saveUrl, "");
    assert.equal(client.manualCode, "");
    await client.save();
    assert.equal(calls, 1);
});

for (const [name, request, outcome] of [
    ["confirmed issuance", async () => response(201, handoff), "ready"],
    [
        "safe rejection",
        async () =>
            response(502, { outcome: "definitive", stage: "provider", retry_allowed: true }),
        "retryable",
    ],
    [
        "unknown issuance",
        async () => response(503, { outcome: "unknown", stage: "provider", retry_allowed: false }),
        "blocked",
    ],
    [
        "redirected response",
        async () => ({ redirected: true, status: 201, json: async () => handoff }),
        "blocked",
    ],
    [
        "lost response",
        async () => {
            throw new Error("private provider message");
        },
        "blocked",
    ],
]) {
    test(`announces ${name} once with only the safe outcome`, async () => {
        const announcements = [];
        const client = customerPassAcquisition("/join/business/save", "csrf", request);
        await client.save((...args) => announcements.push(args));
        assert.deepEqual(announcements, [[outcome]]);
        assert.equal(JSON.stringify(announcements).includes(handoff.manual_code), false);
        assert.equal(JSON.stringify(announcements).includes(handoff.save_url), false);
    });
}

test("duplicate calls, ready-link reuse and teardown never announce another result", async () => {
    let complete;
    const announcements = [];
    const announce = (outcome) => announcements.push(outcome);
    const client = customerPassAcquisition(
        "/join/business/save",
        "csrf",
        () =>
            new Promise((resolve) => {
                complete = resolve;
            }),
    );
    const pending = client.save(announce);
    await client.save(announce);
    assert.deepEqual(announcements, []);
    complete(response(201, handoff));
    await pending;
    await client.save(announce);
    client.destroy();
    await client.save(announce);
    assert.deepEqual(announcements, ["ready"]);
});

for (const rejects of [false, true]) {
    test(`disposal suppresses late ${rejects ? "failure" : "success"} feedback`, async () => {
        let settle;
        const announcements = [];
        const client = customerPassAcquisition(
            "/join/business/save",
            "csrf",
            () =>
                new Promise((resolve, reject) => {
                    settle = rejects ? reject : resolve;
                }),
        );
        const pending = client.save((outcome) => announcements.push(outcome));
        client.destroy();
        settle(rejects ? new Error("private late exception") : response(201, handoff));
        await pending;
        assert.deepEqual(announcements, []);
        assert.equal(client.state, "blocked");
    });
}

test("each permitted explicit retry announces its own result without carrying old feedback", async () => {
    const announcements = [];
    let calls = 0;
    const client = customerPassAcquisition("/join/business/save", "csrf", async () => {
        calls++;
        return calls === 1
            ? response(502, { outcome: "definitive", stage: "provider", retry_allowed: true })
            : response(201, handoff);
    });
    await client.save((outcome) => announcements.push(outcome));
    await client.save((outcome) => announcements.push(outcome));
    assert.deepEqual(announcements, ["retryable", "ready"]);
});

for (const manualCode of ["00000", "99999"]) {
    test(`retains the numeric handoff boundary ${manualCode} as a string`, async () => {
        const client = customerPassAcquisition("/join/business/save", "csrf", async () =>
            response(201, { ...handoff, manual_code: manualCode }),
        );
        await client.save();
        assert.equal(client.state, "ready");
        assert.equal(client.manualCode, manualCode);
    });
}

for (const manualCode of [
    "ABC123DEF456",
    "1234",
    "123456",
    "１２３４５",
    "1234a",
    "12345\n",
    12345,
]) {
    test(`rejects a nonissuance manual-code format ${JSON.stringify(manualCode)}`, async () => {
        const client = customerPassAcquisition("/join/business/save", "csrf", async () =>
            response(201, { ...handoff, manual_code: manualCode }),
        );
        await client.save();
        assert.equal(client.state, "blocked");
        assert.equal(client.manualCode, "");
        assert.equal(client.saveUrl, "");
    });
}

test("presents only the explicit safe unconfigured response and never retries that page", async () => {
    let calls = 0;
    const announcements = [];
    const client = customerPassAcquisition("/join/business/save", "csrf", async () => {
        calls++;
        return response(409, {
            outcome: "definitive",
            stage: "preparation",
            retry_allowed: false,
            code: "business_unconfigured",
            message: "private provider details",
        });
    });
    await client.save((outcome) => announcements.push(outcome));
    assert.equal(client.state, "unconfigured");
    assert.equal(client.saveUrl, "");
    assert.equal(client.manualCode, "");
    assert.deepEqual(announcements, ["unconfigured"]);
    await client.save();
    assert.equal(calls, 1);
});

for (const [name, status, overrides] of [
    ["wrong status", 503, {}],
    ["unknown outcome", 409, { outcome: "unknown" }],
    ["provider stage", 409, { stage: "provider" }],
    ["retry permission", 409, { retry_allowed: true }],
    ["unknown code", 409, { code: "business_invalid" }],
]) {
    test(`does not mistake ${name} for a safe unconfigured response`, async () => {
        const client = customerPassAcquisition("/join/business/save", "csrf", async () =>
            response(status, {
                outcome: "definitive",
                stage: "preparation",
                retry_allowed: false,
                code: "business_unconfigured",
                ...overrides,
            }),
        );
        await client.save();
        assert.equal(client.state, "blocked");
    });
}
