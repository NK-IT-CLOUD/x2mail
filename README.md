# X2Mail — Nextcloud Webmail with Native SSO

Feature-rich webmail client for Nextcloud 33 with native Single Sign-On via OAuth2 (OAUTHBEARER/XOAUTH2). Users log into Nextcloud via SSO and get webmail without a second login.

## How It Works

X2Mail bridges your Nextcloud OIDC login to your IMAP server. The OIDC access token is used directly for IMAP authentication — no extra passwords anywhere.

```
User → Keycloak SSO → Nextcloud (user_oidc)
  → X2Mail takes access token from session
  → IMAP AUTHENTICATE OAUTHBEARER <token>
  → Dovecot validates token via introspection → Keycloak
  → Mailbox opens — zero extra login
```

## Prerequisites

### 1. Nextcloud with OIDC Login

Nextcloud must have SSO login working via one of these apps:

- **`user_oidc`** (recommended) — official Nextcloud OIDC app
- **`oidc_login`** — third-party alternative

```bash
occ app:install user_oidc
occ user_oidc:provider YourProvider \
  -c YOUR_CLIENT_ID \
  -s YOUR_CLIENT_SECRET \
  -d https://your-idp.example.com/realms/your-realm/.well-known/openid-configuration
```

### 2. IMAP Server with OAuth2 Support

Your IMAP server must support token-based authentication (OAUTHBEARER/XOAUTH2 SASL).

**Dovecot** (requires 2.4+):
- Enable `auth-oauth2` mechanism
- Configure `passdb` with `oauth2` driver + token introspection endpoint
- Docs: https://doc.dovecot.org/2.4.2/core/config/auth/databases/oauth2.html

### 3. OIDC Provider Configuration

Your OIDC provider (Keycloak, Authentik, etc.) must:
- Include correct **audience** in access tokens (e.g. `aud: "dovecot"`)
- Include **email claim** in access token
- Expose a **token introspection endpoint** for the IMAP server

### 4. SMTP Server

Any SMTP server that accepts mail from your Nextcloud server.

## Installation

```bash
cd /path/to/nextcloud/custom_apps
tar xzf x2mail-*.tar.gz
chown -R www-data:www-data x2mail
occ app:enable x2mail
```

Download the latest tarball from [GitHub Releases](https://github.com/NK-IT-CLOUD/x2mail/releases).

## Setup

### Quick Setup (SSO — default)

```bash
occ x2mail:setup \
  --imap-host dovecot.example.com \
  --imap-port 143 \
  --smtp-host smtp.example.com \
  --smtp-port 25 \
  --domain example.com \
  --sieve
```

The setup command runs preflight checks and shows compact results:

```
✓ IMAP  dovecot.example.com:143 (PLAIN, LOGIN, XOAUTH2, OAUTHBEARER)
✓ SMTP  smtp.example.com:25 (mail.example.com ESMTP Postfix)
✓ OIDC  user_oidc, token_store=ok
```

### Setup Wizard (Browser)

The admin UI at **Settings → X2Mail** includes a setup wizard with the same preflight checks plus live SSO diagnostics:

```
✓ IMAP  mail.example.com:143 (PLAIN, LOGIN, XOAUTH2, OAUTHBEARER)
✓ SMTP  smtp.example.com:25 (ESMTP)
✓ OIDC  user_oidc, token_store=ok
✓ SSO   Active session with valid token
✓ TOKEN email=user@example.com, aud=dovecot,nextcloud, expires=4min
```

The wizard decodes your JWT access token and verifies that the email claim and audience are correct for IMAP authentication.

### Setup Options

| Option | Default | Description |
|---|---|---|
| `--imap-host` | (required) | IMAP server hostname |
| `--imap-port` | 143 | IMAP port |
| `--imap-ssl` | none | `none`, `ssl`, or `tls` |
| `--smtp-host` | same as IMAP | SMTP server hostname |
| `--smtp-port` | 25 | SMTP port |
| `--smtp-ssl` | none | `none`, `ssl`, or `tls` |
| `--smtp-auth` | no | Require SMTP authentication |
| `--domain` | (required) | Mail domain (e.g. `example.com`) |
| `--auth` | oauth | `oauth` (SSO) or `plain` (legacy) |
| `--oidc-provider` | user_oidc | `user_oidc` or `oidc_login` |
| `--sieve` | no | Enable Sieve filtering |
| `--skip-checks` | no | Skip connectivity checks |

### Check Status

```bash
occ x2mail:status
```

Shows configured domains, IMAP/SMTP settings, SSO configuration, provider status, and token store.

### Admin Panel

NC admins access the engine admin panel directly via SSO — no separate password. The panel provides domain management, login settings, contacts config, and raw configuration access.

### Legacy: Password Auth

If SSO is not available, X2Mail supports manual password authentication:

```bash
occ x2mail:setup \
  --imap-host mail.example.com \
  --imap-port 993 --imap-ssl ssl \
  --smtp-host mail.example.com \
  --smtp-port 587 --smtp-ssl tls --smtp-auth \
  --domain example.com \
  --auth plain

occ x2mail:settings <uid> <email> [password]
```

## SSO Token Flow

```
1. User opens Nextcloud → Keycloak SSO login
2. user_oidc obtains access token + refresh token
3. X2Mail TokenBridgeListener stores token in session
4. User clicks "Email" → X2Mail reads token
5. IMAP AUTHENTICATE OAUTHBEARER <token>
6. Dovecot validates token → Keycloak introspection
7. Mailbox opens — automatic, no extra login
```

### Token Refresh

Access tokens expire after ~5 minutes. X2Mail's `TokenRefreshMiddleware` automatically refreshes via `user_oidc`'s TokenService on every NC request.

**Requirement:** `user_oidc` must have `store_login_token=1` (set automatically by `occ x2mail:setup`).

## Features

- **SSO Webmail** — Keycloak/OIDC login → IMAP without extra credentials
- **OAuth2 IMAP auth** — OAUTHBEARER + XOAUTH2 (auto-detected)
- **Automatic token refresh** — no session drops
- **Setup Wizard** — preflight checks + JWT token diagnostics
- **Admin Panel via SSO** — NC admin = engine admin, no extra password
- **NC33 native theme** — light + dark mode
- **Sieve filtering** support
- **Nextcloud integration** — Contacts, Files, Calendar
- **Multiple identities** — send from different addresses
- **OpenPGP / S/MIME** encryption
- **`occ` commands** — setup, status, settings

## Troubleshooting

### "Login form appears instead of mailbox"
- `occ x2mail:status` — is OIDC auto-login enabled?
- Is `store_login_token=1` set for user_oidc?
- Are you logged in via SSO (not direct NC login)?

### "IMAP authentication failed"
- Run the setup wizard and check the TOKEN line — is email claim present?
- Does the audience include your IMAP server (e.g. `dovecot`)?
- Can Dovecot reach the OIDC introspection endpoint?
- Check: `journalctl -u dovecot | grep auth`

### "Admin panel not loading"
- Are you a Nextcloud admin? (NC admin = engine admin via SSO)
- Hard-refresh browser: Ctrl+Shift+R

## Requirements

- Nextcloud 33+
- PHP 8.4+
- IMAP server with OAUTHBEARER (Dovecot 2.4+)
- OIDC provider (Keycloak, Authentik, etc.) + `user_oidc`

## Development

```bash
git clone https://github.com/NK-IT-CLOUD/x2mail.git
cd x2mail
make build    # Build release tarball
```

See [CHANGELOG.md](CHANGELOG.md) for version history.
See [RELEASE.md](RELEASE.md) for release process.

## Origin

X2Mail is a permanent fork of [SnappyMail v2.38.2](https://github.com/the-djmaze/snappymail/releases/tag/v2.38.2) — the last release of the project. Rebuilt for Nextcloud 33 with native OIDC/SSO, full rebrand, and ongoing maintenance.

## License

AGPL-3.0 — see [LICENSE](LICENSE)
