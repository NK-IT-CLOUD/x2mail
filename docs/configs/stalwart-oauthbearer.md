# Stalwart with OAUTHBEARER/XOAUTH2

Reference setup for X2Mail with Stalwart, using OIDC token validation and an
optional LDAP directory. All values are masked examples.

## Scope

- IMAP auth: Stalwart (`OAUTHBEARER` / `XOAUTH2`)
- SMTP submission auth: Stalwart (`OAUTHBEARER` / `XOAUTH2`)
- ManageSieve auth: Stalwart (`OAUTHBEARER` / `XOAUTH2`)
- IdP example: Keycloak
- Directory example: LDAP/LLDAP

## 1) Stalwart requirements

- Stalwart is configured with the OIDC provider (issuer/JWKS or introspection)
- The mail domain exists and is enabled
- The user identity mapping uses the same claim as the token (`email` recommended)
- The TLS certificates are valid for the hostnames Nextcloud connects to

## 2) Listener strategy

Pick one TLS strategy and use the matching X2Mail values:

- STARTTLS style (example):
  - IMAP `143` + `--imap-ssl starttls`
  - SMTP `587` + `--smtp-ssl starttls`
  - Sieve `4190` + `--sieve-ssl starttls`

- Implicit TLS style (example):
  - IMAP `993` + `--imap-ssl ssl`
  - SMTP `465` + `--smtp-ssl ssl`
  - Sieve `4190` (if implicit configured) + `--sieve-ssl ssl`

## 3) X2Mail setup example

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

## 4) Identity and audience

The token must contain:

- `aud` including your **mail** OIDC client id (a dedicated Keycloak client or an audience mapper, not a Webadmin-only client)
- stable mailbox identity claim (typically `email`; set Stalwart `claimUsername` to `email`)

Keycloak (external IdP) checklist:

1. Create a mail-scoped client (example id: `mail-service`) or add an **Audience** mapper on the Nextcloud client so access tokens include that client in `aud`.
2. In Stalwart: OIDC directory with the same issuer as Nextcloud, `requireAudience` matching that client id, `claimUsername` = `email`.
3. X2Mail domain profile must match the mailbox domain (e.g. `example.com` for `user@example.com`).
4. Optional: `--oidc-audience mail-service` when the login token does not already carry the mail audience (token exchange).

Stalwart's Webadmin/Management uses Stalwart's internal OAuth. Current releases do not support SSO with an external IdP for the admin UI. Configure mail access via OIDC and optional LDAP, and use Stalwart's recovery/fallback admin to manage the server.

## 5) Troubleshooting

- `AUTHENTICATIONFAILED` + domain errors:
  - missing/disabled mail domain in Stalwart
- Sieve TLS/auth mismatch:
  - X2Mail `--sieve-ssl` does not match listener mode
- TLS verify failures from Nextcloud:
  - missing CA trust chain for mail certificate issuer
- SMTP temporary auth failure:
  - OIDC validation path broken or audience mismatch

## 6) LDAP/LLDAP note

LDAP/LLDAP can serve the mailbox directory lookups. Whether an OAuth login succeeds
still depends on token validation and on a consistent identity mapping.
