// DEV-ONLY: JavaScript source injected into the page by scripts/dev/keyboard-audit.mjs (read-only probes:
// focus descriptors, focus-indicator measurement and contrast). It never changes the page.
export const KB_PAGE_SOURCE = String.raw`(() => {
  if (window.__kb) return true;
  const parse = (c) => {
    const m = /rgba?\(([^)]+)\)/.exec(c || '');
    if (!m) return null;
    const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number);
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  };
  const over = (top, bottom) => {
    const a = top.a + bottom.a * (1 - top.a);
    if (a === 0) return { r: 0, g: 0, b: 0, a: 0 };
    return { r: (top.r * top.a + bottom.r * bottom.a * (1 - top.a)) / a, g: (top.g * top.a + bottom.g * bottom.a * (1 - top.a)) / a, b: (top.b * top.a + bottom.b * bottom.a * (1 - top.a)) / a, a };
  };
  const lum = (c) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
  };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const root = () => parse(getComputedStyle(document.body).backgroundColor) || { r: 255, g: 255, b: 255, a: 1 };
  // Effective background behind an element: composite the ancestors' background colours (images are ignored).
  const backdrop = (el) => {
    const chain = [];
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const c = parse(getComputedStyle(n).backgroundColor);
      if (c && c.a > 0) { chain.push(c); if (c.a >= 0.999) break; }
    }
    let base = chain.length && chain[chain.length - 1].a >= 0.999 ? chain.pop() : root();
    if (base.a < 1) base = over(base, { r: 255, g: 255, b: 255, a: 1 });
    while (chain.length) base = over(chain.pop(), base);
    return base;
  };
  const snap = (el) => {
    const s = getComputedStyle(el);
    return { ow: parseFloat(s.outlineWidth) || 0, os: s.outlineStyle, oc: s.outlineColor, oo: parseFloat(s.outlineOffset) || 0, bs: s.boxShadow, bc: s.borderTopColor + '|' + s.borderRightColor + '|' + s.borderBottomColor + '|' + s.borderLeftColor, bw: s.borderTopWidth, bg: s.backgroundColor, color: s.color, td: s.textDecorationLine };
  };
  const snapChain = (el) => {
    const out = [];
    for (let n = el, i = 0; n && n.nodeType === 1 && i < 4; n = n.parentElement, i++) out.push({ tag: n.tagName, snap: snap(n) });
    return out;
  };
  const shadows = (str) => {
    if (!str || str === 'none') return [];
    const res = [];
    let depth = 0, cur = '';
    for (const ch of str) { if (ch === '(') depth++; if (ch === ')') depth--; if (ch === ',' && depth === 0) { res.push(cur.trim()); cur = ''; } else cur += ch; }
    if (cur.trim()) res.push(cur.trim());
    return res.map((part) => {
      const color = /rgba?\([^)]+\)/.exec(part);
      const rest = part.replace(/rgba?\([^)]+\)/, '').replace('inset', '').trim().split(/\s+/).map((v) => parseFloat(v));
      return { color: color ? parse(color[0]) : null, x: rest[0] || 0, y: rest[1] || 0, blur: rest[2] || 0, spread: rest[3] || 0, inset: part.includes('inset') };
    }).filter((x) => x.color && x.color.a > 0);
  };
  // Sides on which a focus ring of the given thickness is cut off by an overflow-clipping ancestor or the viewport.
  const clipped = (node, inflate) => {
    const r = node.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) return '';
    const sides = new Set();
    const test = (name, ar) => {
      if (r.left - inflate < ar.left - 0.5) sides.add(name + ':left');
      if (r.right + inflate > ar.right + 0.5) sides.add(name + ':right');
      if (r.top - inflate < ar.top - 0.5) sides.add(name + ':top');
      if (r.bottom + inflate > ar.bottom + 0.5) sides.add(name + ':bottom');
    };
    for (let a = node.parentElement; a && a !== document.documentElement && a !== document.body; a = a.parentElement) {
      const cs = getComputedStyle(a);
      if (cs.overflowX === 'visible' && cs.overflowY === 'visible') continue;
      const ar = a.getBoundingClientRect();
      if (cs.overflowX === 'visible') { const t = r.top - inflate < ar.top - 0.5, b = r.bottom + inflate > ar.bottom + 0.5; if (t) sides.add((a.className || a.tagName).toString().split(' ')[0] + ':top'); if (b) sides.add((a.className || a.tagName).toString().split(' ')[0] + ':bottom'); continue; }
      test((a.className || a.tagName).toString().split(' ')[0], ar);
    }
    const vw = document.documentElement.clientWidth;
    if (r.left - inflate < -0.5) sides.add('viewport:left');
    if (r.right + inflate > vw + 0.5) sides.add('viewport:right');
    return Array.from(sides).join(' ');
  };
  // focused/unfocused: arrays from snapChain. Returns { kind, ratio, detail } for the best indicator found.
  const evaluate = (el, focused, unfocused) => {
    let best = { kind: 'none', ratio: 0, detail: '' };
    const consider = (kind, r, detail, clip) => { if (r > best.ratio) best = { kind, ratio: Math.round(r * 100) / 100, detail, clip: clip || '' }; };
    let node = el;
    for (let i = 0; i < focused.length && node; i++, node = node.parentElement) {
      const f = focused[i].snap, u = unfocused[i] ? unfocused[i].snap : f;
      const parentBack = backdrop(node.parentElement || node);
      const ownBack = backdrop(node);
      const where = i === 0 ? 'self' : 'ancestor<' + node.tagName.toLowerCase() + '.' + (node.className || '').toString().split(' ')[0] + '>';
      if (f.ow > 0 && f.os !== 'none' && (f.ow !== u.ow || f.oc !== u.oc || f.os !== u.os)) {
        const c = parse(f.oc);
        if (c && c.a > 0) consider('outline ' + f.ow + 'px', ratio(over(c, parentBack), parentBack) * (f.ow >= 2 ? 1 : 0.999), where, clipped(node, Math.max(f.ow + f.oo, 0)));
      }
      if (f.bs !== u.bs) {
        const fs = shadows(f.bs), us = shadows(u.bs);
        for (const sh of fs) {
          if (us.some((x) => JSON.stringify(x) === JSON.stringify(sh))) continue;
          const solid = sh.blur <= 2 || sh.spread >= 1;
          const c = over(sh.color, sh.inset ? ownBack : parentBack);
          const r = ratio(c, sh.inset ? ownBack : parentBack);
          consider((solid ? 'ring ' + sh.spread + 'px' : 'glow blur ' + sh.blur + 'px') + (sh.inset ? ' inset' : ''), solid ? r : r * 0.9, where, sh.inset ? '' : clipped(node, Math.max(sh.spread, 0) + (solid ? 0 : 0)));
        }
      }
      if (f.bc !== u.bc && parseFloat(f.bw) > 0) {
        const cols = f.bc.split('|').map(parse).filter(Boolean);
        const c = cols[0];
        if (c && c.a > 0) consider('border ' + f.bw, Math.min(ratio(over(c, ownBack), ownBack), ratio(over(c, ownBack), parentBack)), where);
      }
      if (f.bg !== u.bg) {
        const a = over(parse(f.bg) || { r: 0, g: 0, b: 0, a: 0 }, parentBack);
        const b = over(parse(u.bg) || { r: 0, g: 0, b: 0, a: 0 }, parentBack);
        consider('background change', ratio(a, b), where);
      }
    }
    return best;
  };
  const describe = (el) => {
    if (!el || el === document.body || el === document.documentElement) return { tag: 'body', name: '(document)', sel: 'body' };
    const r = el.getBoundingClientRect();
    // Position in page space including inner scroll containers (tables scroll inside their region).
    let sx = window.scrollX, sy = window.scrollY;
    for (let n = el.parentElement; n; n = n.parentElement) { sx += n.scrollLeft || 0; sy += n.scrollTop || 0; }
    const label = (el.getAttribute('aria-label') || el.textContent || el.getAttribute('title') || el.getAttribute('placeholder') || el.getAttribute('name') || el.getAttribute('href') || '').replace(/\s+/g, ' ').trim().slice(0, 48);
    const cls = (el.className && el.className.toString().trim().split(/\s+/)[0]) || '';
    return { tag: el.tagName.toLowerCase() + (el.type ? '[' + el.type + ']' : ''), name: label, cls, id: el.id || '', x: Math.round(r.left + sx), y: Math.round(r.top + sy), w: Math.round(r.width), h: Math.round(r.height),
      visible: r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden', inDialog: !!el.closest('dialog[open]'), tabindex: el.getAttribute('tabindex'), sel: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (cls ? '.' + cls : '') };
  };
  // Unfocused styles of every tabbable control, captured while nothing has focus (so an ancestor that keeps
  // :focus-within because the NEXT stop sits inside it cannot hide the indicator).
  const base = new WeakMap();
  const TABBABLE = 'a[href], button, input, select, textarea, summary, [tabindex], area[href], iframe, audio[controls], video[controls]';
  const baseline = () => {
    const was = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
    if (was) was.blur();
    document.querySelectorAll(TABBABLE).forEach((el) => { try { base.set(el, snapChain(el)); } catch (e) { /* ignore */ } });
    if (was) was.focus();
    return true;
  };
  window.__kb = { base, baseline, snap, snapChain, evaluate, describe, backdrop, parse, ratio };
  return true;
})()`;
