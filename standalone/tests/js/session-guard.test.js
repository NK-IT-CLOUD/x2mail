const { test, expect } = require('bun:test');
const { x2wGuardFetch } = require('../../engine-plugin/standalone/js/session-guard.js');

function fakeLocation(origin) {
  return { origin, assigned: [], assign(u) { this.assigned.push(u); } };
}

test('401 from own origin redirects to login and still returns the response', async () => {
  const loc = fakeLocation('https://webmail.example.org');
  const res = { status: 401, url: 'https://webmail.example.org/?/Json/' };
  const f = x2wGuardFetch(async () => res, loc);
  expect(await f('/?/Json/')).toBe(res);
  expect(loc.assigned).toEqual(['/oidc/login']);
});

test('200 does not redirect', async () => {
  const loc = fakeLocation('https://webmail.example.org');
  await x2wGuardFetch(async () => ({ status: 200, url: 'https://webmail.example.org/' }), loc)('/');
  expect(loc.assigned).toEqual([]);
});

test('401 from a foreign origin does not redirect', async () => {
  const loc = fakeLocation('https://webmail.example.org');
  await x2wGuardFetch(async () => ({ status: 401, url: 'https://evil.example/' }), loc)('https://evil.example/');
  expect(loc.assigned).toEqual([]);
});

test('repeated 401s redirect only once', async () => {
  const loc = fakeLocation('https://webmail.example.org');
  const f = x2wGuardFetch(async () => ({ status: 401, url: 'https://webmail.example.org/' }), loc);
  await f('/'); await f('/');
  expect(loc.assigned).toEqual(['/oidc/login']);
});
