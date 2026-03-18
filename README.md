# X2Mail — Nextcloud Webmail with Native XOAUTH2

Feature-rich webmail client for Nextcloud with native Single Sign-On support via XOAUTH2 and OAUTHBEARER. Users log into Nextcloud via SSO and get webmail without a second login. Powered by the SnappyMail engine.

## What X2Mail Does

X2Mail bridges your Nextcloud OIDC login to your IMAP server. When a user logs into Nextcloud via Keycloak/SSO, X2Mail takes the OIDC access token and uses it to authenticate against your IMAP server via OAUTHBEARER. No extra passwords, no extra login forms.

```
User → Keycloak SSO → Nextcloud (user_oidc)
  → X2Mail takes access token from session
  → IMAP AUTHENTICATE OAUTHBEARER <token>
  → Dovecot validates token via introspection → Keycloak
  → Mailbox opens — zero extra login
```

## Prerequisites

X2Mail requires infrastructure that you set up independently:

### 1. Nextcloud with OIDC Login (required)

Nextcloud must already have SSO login working via one of these apps:

- **`user_oidc`** (recommended) — official Nextcloud OIDC app
- **`oidc_login`** — third-party alternative

Your OIDC provider (Keycloak, Authentik, etc.) must be configured and users must be able to log into Nextcloud via SSO before installing X2Mail.

**For user_oidc setup:**
```bash
occ app:install user_oidc
occ user_oidc:provider YourProvider \
  -c YOUR_CLIENT_ID \
  -s YOUR_CLIENT_SECRET \
  -d https://your-idp.example.com/realms/your-realm/.well-known/openid-configuration
```

### 2. IMAP Server with OAUTHBEARER/XOAUTH2 (required for SSO)

Your IMAP server must support token-based authentication. X2Mail sends the OIDC access token as IMAP credentials — the IMAP server must validate it against your OIDC provider.

**Dovecot example** (most common):
- Enable `auth-oauth2` mechanism
- Configure `passdb` with `oauth2` driver
- Set up token introspection endpoint pointing to your OIDC provider
- Dovecot docs: https://doc.dovecot.org/configuration_manual/authentication/oauth2/

**What Dovecot needs:**
- OAUTHBEARER and/or XOAUTH2 SASL mechanisms enabled
- Token introspection: Dovecot must be able to call your OIDC provider's introspection endpoint to validate tokens
- A service account or client credentials for the introspection call
- User lookup: Dovecot must map the `email` claim from the token to a mailbox

### 3. OIDC Provider Configuration

Your OIDC provider (Keycloak, Authentik, etc.) must:

- **Include the correct audience** in access tokens for the IMAP server
  - Keycloak: Add an "audience" protocol mapper to your Nextcloud client (e.g. audience: `dovecot`)
- **Include the email claim** in the access token (usually default)
- **Have a token introspection endpoint** accessible by your IMAP server

**Keycloak example:**
1. Clients → Your NC Client → Protocol Mappers
2. Add mapper: Type "Audience", Client Audience: `dovecot`, Add to access token: ON
3. This ensures the access token contains `aud: ["dovecot", "your-nc-client"]`

### 4. SMTP Server (required for sending)

Any SMTP server that accepts mail from your Nextcloud server. Can be:
- Same server as IMAP (Postfix on Dovecot host)
- A relay/gateway (e.g. Proxmox Mail Gateway)
- An external SMTP service

SMTP can use password auth, no auth (trusted network), or XOAUTH2 — configured during setup.

## Installation

### From Release

```bash
# Download release
wget https://github.com/NK-IT-CLOUD/x2mail/releases/download/v0.1.0/x2mail-0.1.0.tar.gz

# Extract to NC custom_apps
cd /path/to/nextcloud/custom_apps
tar xzf x2mail-0.1.0.tar.gz

# Set permissions
chown -R www-data:www-data x2mail

# Enable
occ app:enable x2mail
```

### From Source

```bash
git clone https://github.com/NK-IT-CLOUD/x2mail.git
cd x2mail
make update-core   # Download SnappyMail engine
make build         # → build/x2mail-VERSION.tar.gz
# Then extract to custom_apps as above
```

## Setup

### Quick Setup (SSO with OAUTHBEARER)

```bash
occ x2mail:setup \
  --imap-host dovecot.example.com \
  --imap-port 143 \
  --smtp-host smtp.example.com \
  --smtp-port 25 \
  --domain example.com \
  --auth oauthbearer \
  --oidc-provider user_oidc \
  --sieve
```

### What `occ x2mail:setup` does

1. **Preflight checks:**
   - Verifies OIDC provider app is installed and configured
   - Tests TCP connectivity to IMAP server
   - Checks IMAP CAPABILITY for OAUTHBEARER/XOAUTH2 support
   - Tests TCP connectivity to SMTP server
   - Enables `store_login_token=1` for user_oidc (required for token refresh)

2. **Configuration:**
   - Writes domain config (IMAP host, SMTP host, auth methods, SSL, Sieve)
   - Sets Nextcloud app config for auto-login
   - Configures SnappyMail engine paths

### Setup with Plain Password Auth

If you don't have OIDC/SSO, X2Mail also works with traditional password authentication:

```bash
occ x2mail:setup \
  --imap-host mail.example.com \
  --imap-port 993 --imap-ssl ssl \
  --smtp-host mail.example.com \
  --smtp-port 587 --smtp-ssl tls --smtp-auth \
  --domain example.com \
  --auth plain
```

Users will see the SnappyMail login form and enter their email/password manually.

### Check Status

```bash
occ x2mail:status
```

Shows configured domains, IMAP/SMTP settings, auth methods, OIDC status, and SnappyMail engine version.

### Setup Options

| Option | Default | Description |
|---|---|---|
| `--imap-host` | (required) | IMAP server hostname or IP |
| `--imap-port` | 143 | IMAP port |
| `--imap-ssl` | none | `none`, `ssl` (port 993), or `tls` (STARTTLS) |
| `--smtp-host` | same as IMAP | SMTP server hostname or IP |
| `--smtp-port` | 25 | SMTP port |
| `--smtp-ssl` | none | `none`, `ssl`, or `tls` |
| `--smtp-auth` | no | Require SMTP authentication |
| `--domain` | (required) | Mail domain (e.g. `example.com`) |
| `--auth` | plain | `plain` or `oauthbearer` (SSO via OAUTHBEARER/XOAUTH2) |
| `--oidc-provider` | user_oidc | `user_oidc` (recommended) or `oidc_login` |
| `--sieve` | no | Enable Sieve filtering |
| `--skip-checks` | no | Skip connectivity and capability checks |

## How SSO Login Works

```
1. User opens Nextcloud → redirected to Keycloak SSO
2. Keycloak authenticates → returns authorization code
3. user_oidc exchanges code for access token + refresh token
4. X2Mail TokenBridgeListener stores access token in PHP session
5. X2Mail LoginBridgeListener stores user ID in session
6. User clicks "Email" in Nextcloud navigation
7. SnappyMail engine reads token from session
8. IMAP AUTHENTICATE OAUTHBEARER with the access token
9. IMAP server validates token against Keycloak (introspection)
10. Mailbox opens — automatic, no extra login
```

### Token Refresh

Access tokens typically expire after 5 minutes. X2Mail's `TokenRefreshMiddleware` runs on every Nextcloud request and automatically refreshes the token via `user_oidc`'s built-in TokenService. The fresh token is written back to the session so SnappyMail always has a valid token.

**Requirement:** `user_oidc` must have `store_login_token=1` (automatically set by `occ x2mail:setup`).

## user_oidc vs oidc_login

| | user_oidc | oidc_login |
|---|---|---|
| Maintainer | Nextcloud GmbH (official) | Community (pulsejet) |
| Token refresh | Yes (built-in TokenService) | No |
| Recommended | Yes | Legacy support only |
| Setup command | auto-sets `store_login_token=1` | No extra config needed |

**Recommendation:** Use `user_oidc`. It's the official NC app with proper token lifecycle management. X2Mail's auto-refresh only works with `user_oidc`.

## Features

- **SSO Webmail** — Keycloak/OIDC login → IMAP without extra credentials
- **OAUTHBEARER / XOAUTH2** — token-based IMAP authentication
- **Automatic token refresh** — no session drops after token expiry
- **Password auth fallback** — works without OIDC too
- **Dark mode** and responsive design
- **Full Sieve filtering** support
- **Nextcloud integration** — Contacts, Files, Calendar, Unified Search
- **Multiple identities** — send from different email addresses
- **OpenPGP and S/MIME** encryption
- **`occ` commands** — setup, status, settings via command line

## Troubleshooting

### "Login form appears instead of mailbox"

- Check `occ x2mail:status` — is OIDC auto-login enabled?
- Is `store_login_token=1` set for user_oidc?
- Are you logged in via SSO (not direct NC login)?

### "AuthError / IMAP authentication failed"

- Does your IMAP server support OAUTHBEARER? Check `occ x2mail:setup` preflight output
- Does your OIDC provider include the correct audience in the token?
- Can Dovecot reach the OIDC introspection endpoint?
- Check Dovecot logs: `journalctl -u dovecot | grep auth`

### "Static files not loading / JS errors"

- Check that `app_path` in SM config matches the NC custom_apps path
- Run `occ x2mail:status` to verify app_path
- Restart PHP-FPM container after deploy to clear OPcache: `docker restart nextcloud`

## Requirements

- Nextcloud 33 (tested), other versions untested
- PHP 8.4+
- IMAP server (Dovecot recommended)
- OIDC provider (Keycloak, Authentik, etc.) for SSO mode
- `user_oidc` or `oidc_login` NC app

## Development

```bash
git clone https://github.com/NK-IT-CLOUD/x2mail.git
cd x2mail
make update-core    # Download SnappyMail engine
make build          # Build release tarball
make clean          # Clean build artifacts
```

See [RELEASE.md](RELEASE.md) for versioning and release process.
See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

AGPL-3.0 — see [LICENSE](LICENSE)
