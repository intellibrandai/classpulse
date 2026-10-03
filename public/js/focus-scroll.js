// Keyboard focus helper (classic script, deferred). Chrome does not always scroll a horizontally scrolling strip
// (filter chips, class cards, table regions) when Tab lands on a control that is only partly visible, which leaves
// the focus ring cut off. On focus, a control that sticks out of its horizontal scroller is scrolled into view.
(function () {
  // Pure geometry: does the rect stick out of the box on the left or the right?
  // `inset` is the width of a sticky first column that covers the scroller's left edge (0 when there is none).
  function sticksOutInline(rect, box, inset) {
    return rect.left < box.left + (inset || 0) - 0.5 || rect.right > box.right + 0.5;
  }

  // A table cell scrolls under the sticky first cell of its row (Weekly Matrix student column): how far in does it reach?
  function stickyInset(target, scroller) {
    var row = target.closest ? target.closest('tr') : null;
    var first = row ? row.firstElementChild : null;
    if (first && first !== target && !first.contains(target) && getComputedStyle(first).position === 'sticky') {
      return Math.max(0, first.getBoundingClientRect().right - scroller.getBoundingClientRect().left);
    }
    return 0;
  }

  function isHorizontalScroller(node) {
    if (typeof getComputedStyle !== 'function') {
      return false;
    }
    var overflowX = getComputedStyle(node).overflowX;
    return (overflowX === 'auto' || overflowX === 'scroll') && node.scrollWidth > node.clientWidth + 1;
  }

  function onFocusIn(event) {
    var target = event.target;
    if (!target || !target.getBoundingClientRect || !target.scrollIntoView) {
      return;
    }
    for (var node = target.parentElement; node && node !== document.body; node = node.parentElement) {
      if (isHorizontalScroller(node)) {
        var inset = stickyInset(target, node);
        if (inset > 0 && target.getBoundingClientRect().left < node.getBoundingClientRect().left + inset - 0.5) {
          // Hidden under the sticky column: scroll the strip back so the cell (and its focus ring) clears it.
          node.scrollLeft -= node.getBoundingClientRect().left + inset + 6 - target.getBoundingClientRect().left;
          return;
        }
        if (sticksOutInline(target.getBoundingClientRect(), node.getBoundingClientRect(), inset)) {
          // A strip with scroll snapping would pull a "nearest" scroll back to the previous snap point.
          var snaps = getComputedStyle(node).scrollSnapType !== 'none';
          target.scrollIntoView({ block: 'nearest', inline: snaps ? 'start' : 'nearest' });
        }
        return;
      }
    }
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('focusin', onFocusIn);
  }

  var api = { sticksOutInline: sticksOutInline };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { globalThis.ClassPulseFocusScroll = api; }
})();
