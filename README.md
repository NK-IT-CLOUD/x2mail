# X2Mail: Nextcloud webmail with native SSO

X2Mail is a webmail client for **Nextcloud 33, 34 and 35**. It signs users in to their mailbox
with OAuth2 SASL (`OAUTHBEARER` / `XOAUTH2`), using the token from their Nextcloud SSO session.
Users log into Nextcloud through your OIDC provider and open their mail without a second login
or a stored mail password.

## How it works

X2Mail takes the OIDC access token from the Nextcloud SSO session and uses it to authenticate
against the mail server.

```text
User -> OIDC provider (Keycloak, Authentik, ...)
     -> Nextcloud (user_oidc)
     -> X2Mail reads access token from session
     -> IMAP/SMTP/Sieve: AUTHENTICATE OAUTHBEARER <token>
     -> Mail server validates token (introspection/JWKS)
     -> Mailbox opens
```

The same token is used for IMAP, SMTP submission and, if enabled, ManageSieve. X2Mail refreshes
it through `user_oidc` before it expires.

## Goal

After a Nextcloud SSO login, users reach their mail with the **same OIDC access token**. There is
no separate webmail password.

## What X2Mail requires from the mail server

X2Mail is a webmail client. It does not replace your MTA, gateway or spam filter.

### Required capabilities

- IMAP must accept OAuth SASL: the server advertises `AUTH=OAUTHBEARER` and/or `AUTH=XOAUTH2`.
- SMTP submission must accept the same token for authenticated sending.
- The mail server validates access tokens against your IdP.
- A token claim maps to the mailbox address, typically `email`.
- ManageSieve is optional. If you enable it in X2Mail, host, port and TLS mode must match the
  Sieve listener.


### Mail servers verified with X2Mail (OAuth SASL)

We have **tested these stacks end to end** with X2Mail: IMAP, SMTP submission and optional
ManageSieve via `OAUTHBEARER` / `XOAUTH2`, Keycloak audience mapping, and the wizard's
**Test Login**.

| Stack | Role | Setup guide |
|---|---|---|
| **Dovecot 2.4+ + Postfix** | IMAP on Dovecot; SMTP submission auth via Dovecot SASL (Dovecot 2.4 `oauth2` settings + OIDC introspection/JWKS) | [dovecot-postfix-oauthbearer.md](docs/configs/dovecot-postfix-oauthbearer.md) |
| **Stalwart 0.16+** | Integrated IMAP, SMTP submission and ManageSieve; OIDC validation + optional LDAP directory | [stalwart-oauthbearer.md](docs/configs/stalwart-oauthbearer.md) |

IdP configuration (Keycloak example, audience mapper, `email` claim): [keycloak.md](docs/configs/keycloak.md).

Other products can work if they offer the same OAuth SASL on IMAP and submission, but they are
not on this list. Check them yourself with the preflight and the wizard's **Test Login**.

We have **not** verified integrated stacks such as mailcow. Out of the box they have no
supported, persistent OAuth2 SASL path for external IdPs. Community overrides exist, but they
are not equivalent to the Dovecot or Stalwart setups above.

### Deployment topologies

The requirements are the same whether everything runs on one host or on several:

- Nextcloud, mail server and IdP can sit on different machines, VLANs or sites.
- A gateway such as PMG, Rspamd or another MTA/filter can sit in front of delivery. X2Mail
  still connects only to the **IMAP**, **SMTP submission** and **ManageSieve** endpoints of the
  mail server that performs OAuth SASL.


## Prerequisites

### 1. Nextcloud with OIDC login

Install and configure the OIDC app `user_oidc`:

```bash
occ app:install user_oidc
occ user_oidc:provider YourProvider \
  -c YOUR_CLIENT_ID \
  -s YOUR_CLIENT_SECRET \
  -d https://idp.example.com/realms/example/.well-known/openid-configuration
```

`occ x2mail:setup` sets `store_login_token=1` for `user_oidc` when needed.

### 2. OAuth support on the mail server

Your mail server must validate OIDC tokens and accept OAuth SASL from mail clients.

Setup guides for specific stacks, with masked example values, are in `docs/configs/`:

- [Keycloak IdP setup](docs/configs/keycloak.md)
- [Dovecot + Postfix](docs/configs/dovecot-postfix-oauthbearer.md)
- [Stalwart](docs/configs/stalwart-oauthbearer.md)

The guides are published with each release on the [GitHub mirror](https://github.com/NK-IT-CLOUD/x2mail).
The app package from the App Store does not include them.

### 3. OIDC audience and claims

The mail server accepts a token only if one of these is true:

- `aud` includes the mail server's OIDC client (an audience mapper is the usual way), or
- X2Mail token exchange is configured (`--oidc-audience`, optionally `--oidc-scopes`).

The token also needs a claim that identifies the mailbox, typically `email`.

Details: [docs/configs/keycloak.md](docs/configs/keycloak.md)

## Installation

### Nextcloud App Store (recommended)

X2Mail is in the official app catalog:

- [X2Mail on apps.nextcloud.com](https://apps.nextcloud.com/apps/x2mail)

In the Nextcloud web UI, go to **Apps**, search for **X2Mail** and choose **Download and enable**.
Nextcloud installs updates automatically when a new signed release reaches the App Store.

After installation, configure the mail connection in **Settings > X2Mail** or with `occ x2mail:setup`.

### Manual install (tarball)

To install by hand, download a release tarball from
[GitHub Releases](https://github.com/NK-IT-CLOUD/x2mail/releases):

```bash
cd /path/to/nextcloud/custom_apps
tar xzf x2mail-*.tar.gz
chown -R www-data:www-data x2mail
occ app:enable x2mail
occ x2mail:setup ...
```

The tarball is the same app package that the App Store delivers.

### Admin settings

X2Mail is administered in **Nextcloud Settings > X2Mail**. The page has four sections:

- Setup wizard: IMAP, SMTP and Sieve hosts, ports and TLS modes, the OIDC provider and an
  optional token exchange audience. It includes a connectivity preflight and a *Test Login*
  button that performs a real OAUTHBEARER login against IMAP, SMTP and ManageSieve with the
  admin's current SSO token.
- General: app menu title (default **X2Mail**, only in the native Nextcloud menu), attachment
  size limit, attachment thumbnails and the OpenPGP/GnuPG switches.
- Advanced: Nextcloud language enforcement, the engine `app_path`, and debug logging for the
  engine and for X2Mail.
- Info: the installed X2Mail version and a link to the project.

Version 0.7.0 removed the old SnappyMail-style engine admin panel.

## Setup

### Quick setup (CLI)

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

**Stalwart** (typical implicit TLS listeners, verified with X2Mail):

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

Example preflight output (the Sieve line only appears with `--sieve`):

```text
✓ IMAP  mail.example.com:143 (XOAUTH2, OAUTHBEARER)
✓ SMTP  mail.example.com:587 (XOAUTH2, OAUTHBEARER)
✓ Sieve mail.example.com:4190 (OAUTHBEARER)
✓ OIDC  user_oidc, token_store=ok
```

### Setup wizard (browser)

Open **Settings > X2Mail**. IMAP, SMTP and Sieve each have their own section. Two buttons test
the configuration:

- **Check connectivity** checks that IMAP, SMTP and Sieve are reachable and advertise OAuth
  SASL. It also checks the OIDC apps and your SSO session. It does not log in to the mailbox.
- **Test Login** performs a real OAUTHBEARER login to IMAP, SMTP and Sieve with your current
  SSO token.

**Test Login** authenticates as your own admin account. If you have no mailbox, it fails even
when the configuration is correct for other users. The login test only exists in the wizard,
because `occ` runs without an SSO session.

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

If a token exchange audience is configured, the **TOKEN** line warns when the token's `aud`
claim does not contain it.

X2Mail keeps one active domain profile. Saving the wizard replaces any older stored profile.

### Setup options

| Option | Default | Description |
|---|---|---|
| `--imap-host` | (required) | IMAP hostname |
| `--imap-port` | `143` | IMAP port |
| `--imap-ssl` | `none` | `none`, `ssl`, `tls`/`starttls` |
| `--smtp-host` | same as IMAP | SMTP hostname |
| `--smtp-port` | `587` | SMTP port |
| `--smtp-ssl` | `none` | `none`, `ssl`, `tls`/`starttls` |
| `--domain` | (required) | Mail domain (`user@domain`) |
| `--oidc-provider` | `user_oidc` | `user_oidc` |
| `--oidc-audience` | (empty) | Token exchange audience/client (optional) |
| `--oidc-scopes` | (empty) | Extra scopes requested during token exchange (optional) |
| `--sieve` | off | Enable ManageSieve |
| `--sieve-host` | same as IMAP | ManageSieve hostname |
| `--sieve-port` | `4190` | ManageSieve port |
| `--sieve-ssl` | `none` | `none`, `ssl`, `tls`/`starttls` |
| `--skip-checks` | off | Skip connectivity preflight |

The generated domain config allows only OAuth SASL (`OAUTHBEARER`, `XOAUTH2`) and turns on SMTP
authentication.

### Check status

```bash
occ x2mail:status
```

This prints the domain profile, the TLS mode of each protocol, the OIDC provider and the state
of the token store.

## SSO token flow

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
- One active domain profile for SSO users
- Setup wizard with preflight checks and live token diagnostics
- Real OAuth login test for IMAP, SMTP and Sieve
- Automatic token refresh
- ManageSieve filters
- Integration with Nextcloud Contacts, Files and Calendar
- Multiple identities, OpenPGP and S/MIME
- `occ x2mail:setup`, `occ x2mail:status`

## Troubleshooting

### Login form appears instead of mailbox

- Run `occ x2mail:status` and check autologin, OIDC and domain.
- `occ config:app:get user_oidc store_login_token` must return `1`.
- The user must have logged in via SSO, not with a local Nextcloud password.
- The configured domain must match the mailbox domain (`user@example.com` needs `example.com`).

### IMAP authentication failed

- Check the wizard's TOKEN line: is `email` present?
- `aud` must include your mail server's OIDC client.
- The mail server must be able to reach the IdP's introspection or JWKS endpoint.
- Run the setup again with the correct host, port and TLS mode.

### SMTP rejected / temporary auth failure

- The submission endpoint must advertise `OAUTHBEARER`/`XOAUTH2`.
- The generated config must have `SMTP.useAuth=true` (the SSO setup sets this by default).
- Check the audience and the token validation path, as for IMAP.

### Sieve test fails while IMAP/SMTP work

- `--sieve-port` and `--sieve-ssl` must match the server's listener.
- STARTTLS and implicit TLS on port `4190` are not interchangeable. Use the mode the server uses.
- Save the wizard again or rerun `occ x2mail:setup` with the corrected Sieve options.

### TLS verify failed in wizard

- Add the issuing CA to the Nextcloud trust store, or use a publicly trusted certificate.
- The hostname in the certificate must match the configured IMAP, SMTP or Sieve host.

### Capability checks

```bash
openssl s_client -connect mail.example.com:143 -starttls imap -quiet
# CAPABILITY should include AUTH=OAUTHBEARER and/or AUTH=XOAUTH2

openssl s_client -connect mail.example.com:587 -starttls smtp -quiet
# EHLO should include AUTH ... OAUTHBEARER ... XOAUTH2
```

For problems specific to one stack, see:

- [docs/configs/dovecot-postfix-oauthbearer.md](docs/configs/dovecot-postfix-oauthbearer.md)
- [docs/configs/stalwart-oauthbearer.md](docs/configs/stalwart-oauthbearer.md)
- [docs/configs/keycloak.md](docs/configs/keycloak.md)


## Development

```bash
git clone https://github.com/NK-IT-CLOUD/x2mail.git
cd x2mail
make build
```

See [CHANGELOG.md](CHANGELOG.md), [RELEASE.md](RELEASE.md) and [SECURITY.md](SECURITY.md).

## Security

Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Origin

X2Mail is a permanent fork of [SnappyMail v2.38.2](https://github.com/the-djmaze/snappymail/releases/tag/v2.38.2),
rebuilt for Nextcloud 33+ with native OIDC/SSO.

## License

AGPL-3.0, see [LICENSE](LICENSE).
