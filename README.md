# X2Mail — Nextcloud Webmail with Native SSO

Feature-rich webmail client for **Nextcloud 33 and 34** with native Single Sign-On via OAuth2
SASL (`OAUTHBEARER` / `XOAUTH2`). Users log into Nextcloud via your OIDC provider and open
webmail without a second login or stored mail password.

## How It Works

X2Mail reuses the OIDC access token from the Nextcloud SSO session and uses it for mail
protocol authentication.

```text
User -> OIDC provider (Keycloak, Authentik, ...)
     -> Nextcloud (user_oidc / oidc_login)
     -> X2Mail reads access token from session
     -> IMAP/SMTP/Sieve: AUTHENTICATE OAUTHBEARER <token>
     -> Mail server validates token (introspection/JWKS)
     -> Mailbox opens
```

The same token is used for IMAP, SMTP submission, and optional ManageSieve. X2Mail refreshes
it through `user_oidc` before expiry.

## Goal

After Nextcloud SSO login, users should access mail with the **same OIDC access token** —
no separate webmail password flow.

## What X2Mail Requires From The Mail Server

X2Mail is a webmail client. It does not replace your MTA, gateway, or spam stack.

### Required Capabilities

- **IMAP OAuth SASL** — server advertises `AUTH=OAUTHBEARER` and/or `AUTH=XOAUTH2`
- **SMTP submission OAuth SASL** — authenticated sending with the same token model
- **OIDC token validation** — mail server validates access tokens against your IdP
- **Stable mail identity** — token claim maps to mailbox address (typically `email`)
- **Optional ManageSieve** — if enabled in X2Mail, Sieve endpoint must match host/port/TLS mode


### Mail servers verified with X2Mail (OAuth SASL)

These stacks are **tested end-to-end** with X2Mail (IMAP + SMTP submission + optional
ManageSieve via `OAUTHBEARER` / `XOAUTH2`, Keycloak audience mapping, wizard **Test Login**):

| Stack | Role | Setup guide |
|---|---|---|
| **Dovecot 2.4+ + Postfix** | IMAP on Dovecot; SMTP submission auth via Dovecot SASL (`oauth2` passdb + OIDC introspection/JWKS) | [dovecot-postfix-oauthbearer.md](docs/configs/dovecot-postfix-oauthbearer.md) |
| **Stalwart 0.16+** | Integrated IMAP, SMTP submission, and ManageSieve; OIDC validation + optional LDAP directory | [stalwart-oauthbearer.md](docs/configs/stalwart-oauthbearer.md) |

IdP configuration (Keycloak example, audience mapper, `email` claim): [keycloak.md](docs/configs/keycloak.md).

Any other product is **not** listed here unless it exposes the same client-facing OAuth SASL
on IMAP and submission and you validate it yourself (preflight + wizard **Test Login**).

**Not verified in this project:** integrated stacks such as **mailcow** do not ship a
supported, persistent OAuth2 SASL path for external IdPs out of the box (community overrides
only; not equivalent to the Dovecot or Stalwart flows above).

### Deployment topologies (independent of mail product)

Same requirements whether services run on one host or many:

- **Split hosts** — Nextcloud, mail server, and IdP on different machines/VLANs/sites
- **Gateway in transport path** — PMG, Rspamd, or another MTA/filter in front of delivery;
  X2Mail still connects only to the **IMAP**, **SMTP submission**, and **ManageSieve**
  endpoints of the mail server that performs OAuth SASL


## Prerequisites

### 1. Nextcloud with OIDC Login

Install and configure one OIDC app:

- `user_oidc` (recommended)
- `oidc_login`

```bash
occ app:install user_oidc
occ user_oidc:provider YourProvider \
  -c YOUR_CLIENT_ID \
  -s YOUR_CLIENT_SECRET \
  -d https://idp.example.com/realms/example/.well-known/openid-configuration
```

`occ x2mail:setup` sets `store_login_token=1` for `user_oidc` when needed.

### 2. Mail Server OAuth Support

Your mail stack must validate OIDC tokens and accept OAuth SASL on client protocols.

Stack-specific setup guides (masked examples, in repository `docs/configs/`):

- [Keycloak IdP setup](docs/configs/keycloak.md)
- [Dovecot + Postfix](docs/configs/dovecot-postfix-oauthbearer.md)
- [Stalwart](docs/configs/stalwart-oauthbearer.md)

These guides are published to the [GitHub mirror](https://github.com/NK-IT-CLOUD/x2mail) on
release (not shipped inside the Nextcloud app package from the App Store).

### 3. OIDC Audience and Claims

The mail server accepts tokens only when:

- `aud` includes the mail-server OIDC client (recommended via audience mapper), or
- X2Mail token exchange is configured (`--imap-audience`)

Required claims:

- user identity for mailbox mapping (typically `email`)

Details: [docs/configs/keycloak.md](docs/configs/keycloak.md)

## Installation

### Nextcloud App Store (recommended)

Install and enable X2Mail from the official app catalog:

- [X2Mail on apps.nextcloud.com](https://apps.nextcloud.com/apps/x2mail)

In the Nextcloud web UI: **Apps** → search **X2Mail** → **Download and enable**.  
Nextcloud applies updates automatically when a new signed release is published to the App Store.

After installation, configure mail connectivity in **Settings → X2Mail** or with `occ x2mail:setup`.

### Manual install (tarball)

For manual deployment, download a release tarball from
[GitHub Releases](https://github.com/NK-IT-CLOUD/x2mail/releases):

```bash
cd /path/to/nextcloud/custom_apps
tar xzf x2mail-*.tar.gz
chown -R www-data:www-data x2mail
occ app:enable x2mail
occ x2mail:setup ...
```

The App Store and manual tarball install the **same app package**; only the delivery path differs.

### Admin Settings

All X2Mail administration lives in **Nextcloud Settings → X2Mail**. The settings page exposes:

- **Setup wizard** — IMAP/SMTP/Sieve hosts + ports + TLS modes, OIDC provider, optional token-exchange audience. Built-in connectivity preflight and a *Test Login* button that performs a real OAUTHBEARER login against IMAP, SMTP, and ManageSieve using the admin's current SSO token.
- **General** — app menu title (default **X2Mail**, native NC menu only), attachment size limit, attachment thumbnails, OpenPGP/GnuPG toggles.
- **Advanced** — Nextcloud language enforcement, engine `app_path`, engine + X2Mail debug logging.
- **Info** — installed X2Mail version + project link.

The legacy SnappyMail-style engine admin panel was removed in 0.7.0.

## Setup

### Quick Setup (CLI)

**Dovecot + Postfix** (typical STARTTLS listeners):

```bash
occ x2mail:setup \
  --imap-host mail.example.com \
  --imap-port 143 --imap-ssl starttls \
  --smtp-host mail.example.com \
  --smtp-port 587 --smtp-ssl starttls \
  --domain example.com \
  --sieve \
  --sieve-host mail.example.com \
  --sieve-port 4190 --sieve-ssl starttls
```

**Stalwart** (typical implicit-TLS listeners — verified with X2Mail):

```bash
occ x2mail:setup \
  --imap-host mail.example.com \
  --imap-port 993 --imap-ssl ssl \
  --smtp-host mail.example.com \
  --smtp-port 465 --smtp-ssl ssl \
  --domain example.com \
  --sieve \
  --sieve-host mail.example.com \
  --sieve-port 4190 --sieve-ssl ssl
```

Preflight example (the Sieve line appears only when `--sieve` is enabled):

```text
✓ IMAP  mail.example.com:143 (XOAUTH2, OAUTHBEARER)
✓ SMTP  mail.example.com:587 (XOAUTH2, OAUTHBEARER)
✓ Sieve mail.example.com:4190 (OAUTHBEARER)
✓ OIDC  user_oidc, token_store=ok
```

### Setup Wizard (Browser)

Open **Settings -> X2Mail**:

- Configure IMAP, SMTP, and Sieve in separate sections
- **Check connectivity** — reachability + advertised OAuth SASL on IMAP/SMTP/Sieve, plus OIDC apps and your SSO session (no mail login)
- **Test Login** — a real OAUTHBEARER login to IMAP/SMTP/Sieve with your current SSO token

**Test Login** authenticates as your own admin account — it fails if you have no
mailbox, even when the configuration is correct for other users. The real
token-based login test is only available in the wizard, not via `occ` (the CLI
has no SSO session).

Example wizard output (connectivity check followed by Test Login):

```text
✓ IMAP  mail.example.com:143 (XOAUTH2, OAUTHBEARER)
✓ SMTP  mail.example.com:587 (XOAUTH2, OAUTHBEARER)
✓ Sieve mail.example.com:4190 (OAUTHBEARER)
✓ OIDC  user_oidc, token_store=ok
✓ SSO   Active session with valid token
✓ TOKEN email=user@example.com, aud=nextcloud,mail-service, expires=11min
✓ IMAP login OK
✓ SMTP login OK
✓ Sieve login OK
```

When a token-exchange audience is configured, the **TOKEN** line warns if that
audience is missing from the token's `aud` claim.

The release setup keeps one active domain profile. Saving replaces older stored profiles.

### Setup Options

| Option | Default | Description |
|---|---|---|
| `--imap-host` | (required) | IMAP hostname |
| `--imap-port` | `143` | IMAP port |
| `--imap-ssl` | `none` | `none`, `ssl`, `tls`/`starttls` |
| `--smtp-host` | same as IMAP | SMTP hostname |
| `--smtp-port` | `587` | SMTP port |
| `--smtp-ssl` | `none` | `none`, `ssl`, `tls`/`starttls` |
| `--domain` | (required) | Mail domain (`user@domain`) |
| `--oidc-provider` | `user_oidc` | `user_oidc` or `oidc_login` |
| `--imap-audience` | (empty) | Token exchange audience/client (optional) |
| `--sieve` | off | Enable ManageSieve |
| `--sieve-host` | same as IMAP | ManageSieve hostname |
| `--sieve-port` | `4190` | ManageSieve port |
| `--sieve-ssl` | `none` | `none`, `ssl`, `tls`/`starttls` |
| `--skip-checks` | off | Skip connectivity preflight |

Generated domain config uses OAuth SASL only (`OAUTHBEARER`, `XOAUTH2`) and enables SMTP auth automatically.

### Check Status

```bash
occ x2mail:status
```

Shows domain profile, protocol security modes, OIDC provider, and token-store status.

## SSO Token Flow

```text
1. User logs into Nextcloud via OIDC
2. user_oidc stores access token (+ refresh token)
3. User opens X2Mail
4. X2Mail performs IMAP AUTHENTICATE OAUTHBEARER <token>
5. Mail server validates token with IdP and opens mailbox
6. Outbound mail uses SMTP AUTH with the same token
7. Optional Sieve uses the same token model
8. TokenRefreshMiddleware refreshes token via user_oidc
```

## Features

- SSO webmail with OAuth SASL (`OAUTHBEARER` / `XOAUTH2`)
- Single active domain profile for SSO users
- Setup wizard with preflight + live token diagnostics
- Real OAuth login test for IMAP/SMTP/Sieve
- Automatic token refresh
- ManageSieve filtering support
- Nextcloud Contacts / Files / Calendar integration
- Multiple identities, OpenPGP / S-MIME
- `occ x2mail:setup`, `occ x2mail:status`

## Troubleshooting

### Login form appears instead of mailbox

- Run `occ x2mail:status` (autologin/OIDC/domain)
- Verify `occ config:app:get user_oidc store_login_token` is `1`
- Ensure login happened via SSO, not local Nextcloud password
- Domain in config must match mailbox domain (`user@example.com` -> `example.com`)

### IMAP authentication failed

- Check wizard TOKEN line: `email` present?
- Check `aud` includes your mail-server OIDC client
- Verify mail server can reach IdP introspection/JWKS endpoint
- Re-run setup with correct host/port/TLS mode

### SMTP rejected / temporary auth failure

- Confirm submission endpoint advertises `OAUTHBEARER`/`XOAUTH2`
- Verify generated config has `SMTP.useAuth=true` (default in SSO setup)
- Check audience and token validation path (same as IMAP)

### Sieve test fails while IMAP/SMTP work

- Align `--sieve-port` and `--sieve-ssl` with server listener mode
- STARTTLS on `4190` vs implicit TLS on `4190` must match exactly
- Re-save wizard or re-run `occ x2mail:setup` with corrected sieve options

### TLS verify failed in wizard

- Install issuing CA in Nextcloud trust store, or use publicly trusted cert
- Ensure hostname in cert matches configured IMAP/SMTP/Sieve host

### Capability checks

```bash
openssl s_client -connect mail.example.com:143 -starttls imap -quiet
# CAPABILITY should include AUTH=OAUTHBEARER and/or AUTH=XOAUTH2

openssl s_client -connect mail.example.com:587 -starttls smtp -quiet
# EHLO should include AUTH ... OAUTHBEARER ... XOAUTH2
```

For stack-specific failures, see:

- [docs/configs/dovecot-postfix-oauthbearer.md](docs/configs/dovecot-postfix-oauthbearer.md)
- [docs/configs/stalwart-oauthbearer.md](docs/configs/stalwart-oauthbearer.md)
- [docs/configs/keycloak.md](docs/configs/keycloak.md)


## Development

```bash
git clone https://github.com/NK-IT-CLOUD/x2mail.git
cd x2mail
make build
```

See [CHANGELOG.md](CHANGELOG.md), [RELEASE.md](RELEASE.md), and [SECURITY.md](SECURITY.md).

## Security

Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Origin

Permanent fork of [SnappyMail v2.38.2](https://github.com/the-djmaze/snappymail/releases/tag/v2.38.2), rebuilt for Nextcloud 33+ with native OIDC/SSO.

## License

AGPL-3.0 — see [LICENSE](LICENSE).
