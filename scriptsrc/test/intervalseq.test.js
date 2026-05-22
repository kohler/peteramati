// intervalseq.test.js -- tests for scriptsrc/intervalseq.js
import { test } from "node:test";
import assert from "node:assert/strict";
import IntervalSeq from "../intervalseq.js";

test("single interval membership", () => {
    const s = new IntervalSeq;
    s.add(1, 3);
    assert.equal(s.contains(0), false);
    assert.equal(s.contains(1), true);
    assert.equal(s.contains(2), true);
    assert.equal(s.contains(3), true);
    assert.equal(s.contains(4), false);
    assert.deepEqual(s.is, [1, 3]);
});

test("disjoint intervals stay separate", () => {
    const s = new IntervalSeq;
    s.add(1, 2);
    s.add(10, 11);
    assert.deepEqual(s.is, [1, 2, 10, 11]);
    assert.equal(s.contains(5), false);
    assert.equal(s.contains(10), true);
    assert.equal(s.contains(11), true);
});

test("add merges overlapping/adjacent intervals", () => {
    const s = new IntervalSeq;
    s.add(1, 3);
    s.add(5, 7);
    s.add(3, 5); // bridges the gap
    assert.deepEqual(s.is, [1, 7]);
    assert.equal(s.contains(4), true);
});

test("add extends the upper bound via Math.max", () => {
    const s = new IntervalSeq;
    s.add(1, 10);
    s.add(2, 4); // wholly contained: must not shrink
    assert.deepEqual(s.is, [1, 10]);
});

test("overlaps", () => {
    const s = new IntervalSeq;
    s.add(1, 7);
    assert.equal(s.overlaps(0, 2), true);
    assert.equal(s.overlaps(3, 4), true);
    assert.equal(s.overlaps(7, 9), true);
    assert.equal(s.overlaps(8, 10), false);
});

test("clear empties the sequence", () => {
    const s = new IntervalSeq;
    s.add(1, 3);
    s.clear();
    assert.deepEqual(s.is, []);
    assert.equal(s.contains(2), false);
});
