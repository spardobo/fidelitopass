import assert from "node:assert/strict";
import { test } from "node:test";
import { calculatePassPresentation } from "../../resources/js/pass-preview-motion.js";

test("card edges keep the original signed eight-degree tilt and bounded sheen", () => {
    assert.deepEqual(calculatePassPresentation(0, 0), {
        angleDegrees: -8,
        sheenX: 10,
        sheenY: 10,
    });
    assert.deepEqual(calculatePassPresentation(1, 1), {
        angleDegrees: 8,
        sheenX: 90,
        sheenY: 90,
    });
});

test("the center dead zone rests while the sheen still follows the pointer", () => {
    assert.deepEqual(calculatePassPresentation(0.5, 0.5), {
        angleDegrees: 0,
        sheenX: 50,
        sheenY: 50,
    });
    assert.equal(calculatePassPresentation(0.47, 0.2).angleDegrees, 0);
    assert.equal(calculatePassPresentation(0.53, 0.8).angleDegrees, 0);
    assert.ok(calculatePassPresentation(0.45, 0.5).angleDegrees < 0);
    assert.ok(calculatePassPresentation(0.55, 0.5).angleDegrees > 0);
});

test("pointer coordinates outside the card never amplify tilt or sheen", () => {
    assert.deepEqual(calculatePassPresentation(-0.2, 1.4), {
        angleDegrees: -8,
        sheenX: 10,
        sheenY: 90,
    });
    assert.deepEqual(calculatePassPresentation(1.4, -0.2), {
        angleDegrees: 8,
        sheenX: 90,
        sheenY: 10,
    });
});
