const { test, expect } = require('bun:test');
const { x2wApplyPrimaryColor } = require('../../engine-plugin/standalone/js/theme.js');

function fakeRoot() {
  return { props: {}, style: { setProperty(name, value) { this.owner.props[name] = value; } } };
}

function root() {
  const r = fakeRoot();
  r.style.owner = r;
  return r;
}

test('valid colour from app data is set as --x2w-primary', () => {
  const r = root();
  x2wApplyPrimaryColor({ settings: { app: name => ({ x2wPrimaryColor: '#aa3300' })[name] } }, r);
  expect(r.props).toEqual({ '--x2w-primary': '#aa3300' });
});

test('absent value leaves the default', () => {
  const r = root();
  x2wApplyPrimaryColor({ settings: { app: () => undefined } }, r);
  expect(r.props).toEqual({});
});

test('invalid values are ignored', () => {
  for (const bad of ['red', '#abc', '#aa3300; x', '#aa3300 ', 42, null]) {
    const r = root();
    x2wApplyPrimaryColor({ settings: { app: () => bad } }, r);
    expect(r.props).toEqual({});
  }
});

test('missing rl or throwing settings do not throw', () => {
  const r = root();
  expect(() => x2wApplyPrimaryColor(undefined, r)).not.toThrow();
  expect(() => x2wApplyPrimaryColor({}, r)).not.toThrow();
  expect(() => x2wApplyPrimaryColor({ settings: { app: () => { throw new TypeError('no System'); } } }, r)).not.toThrow();
  expect(r.props).toEqual({});
});
