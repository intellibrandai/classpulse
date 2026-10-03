'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const lib = require('../../public/js/focus-scroll.js');

const box = { left: 20, right: 350 };

test('a control fully inside its scroller needs no scrolling', () => {
  assert.equal(lib.sticksOutInline({ left: 30, right: 140 }, box), false);
  assert.equal(lib.sticksOutInline({ left: 20, right: 350 }, box), false);
});

test('a control cut off on the right or the left is scrolled into view', () => {
  assert.equal(lib.sticksOutInline({ left: 327, right: 438 }, box), true);
  assert.equal(lib.sticksOutInline({ left: -40, right: 60 }, box), true);
});

test('sub-pixel rounding does not trigger a scroll', () => {
  assert.equal(lib.sticksOutInline({ left: 19.6, right: 350.4 }, box), false);
});
