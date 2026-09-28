/* X2Mail standalone: a 401 from our own origin means the OIDC session ended —
 * go to the login instead of leaving the UI stuck in "Network response error". */
function x2wGuardFetch(fetchImpl, location) {
  let redirected = false;
  return async function (input, init) {
    const response = await fetchImpl(input, init);
    if (response && response.status === 401 && !redirected) {
      let sameOrigin = false;
      try { sameOrigin = new URL(response.url, location.origin).origin === location.origin; } catch (e) { sameOrigin = false; }
      if (sameOrigin) {
        redirected = true;
        location.assign('/oidc/login');
      }
    }
    return response;
  };
}

if (typeof window !== 'undefined' && !window.__x2wGuard && typeof window.fetch === 'function') {
  window.__x2wGuard = true;
  window.fetch = x2wGuardFetch(window.fetch.bind(window), window.location);
}

if (typeof module !== 'undefined') {
  module.exports = { x2wGuardFetch };
}
