/* X2Mail standalone: [theme] primary_color from webmail.toml arrives as
 * System.x2wPrimaryColor in the app data; css/nc-theming.css derives every
 * primary tone from --x2w-primary. Absent or malformed values keep the default. */
function x2wApplyPrimaryColor(rl, root) {
  let color;
  try { color = rl.settings.app('x2wPrimaryColor'); } catch (e) { return; }
  if (typeof color === 'string' && /^#[0-9a-fA-F]{6}$/.test(color)) {
    root.style.setProperty('--x2w-primary', color);
  }
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  x2wApplyPrimaryColor(window.rl, document.documentElement);
}

if (typeof module !== 'undefined') {
  module.exports = { x2wApplyPrimaryColor };
}
