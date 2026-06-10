# Stalwart with OAUTHBEARER/XOAUTH2

Masked reference for running X2Mail against Stalwart with OIDC token validation
and optional LDAP directory backend.

## Scope

- IMAP auth: Stalwart (`OAUTHBEARER` / `XOAUTH2`)
- SMTP submission auth: Stalwart (`OAUTHBEARER` / `XOAUTH2`)
- ManageSieve auth: Stalwart (`OAUTHBEARER` / `XOAUTH2`)
- IdP example: Keycloak
- Directory example: LDAP/LLDAP

## 1) Stalwart Requirements

- Stalwart configured with OIDC provider (issuer/JWKS or introspection)
- Mail domain exists and is enabled
- User identity mapping aligns with token claim (`email` recommended)
- TLS certificates valid for hostnames used by Nextcloud

## 2) Listener Strategy

Pick one TLS strategy and keep X2Mail values aligned:

- STARTTLS style (example):
  - IMAP `143` + `--imap-ssl starttls`
  - SMTP `587` + `--smtp-ssl starttls`
  - Sieve `4190` + `--sieve-ssl starttls`

- Implicit TLS style (example):
  - IMAP `993` + `--imap-ssl ssl`
  - SMTP `465` + `--smtp-ssl ssl`
  - Sieve `4190` (if implicit configured) + `--sieve-ssl ssl`

## 3) X2Mail Setup Example

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

## 4) Identity and Audience

Token must contain:

- `aud` including your **mail** OIDC client id (dedicated Keycloak client or audience mapper — not a Webadmin-only client)
- stable mailbox identity claim (typically `email`; set Stalwart `claimUsername` to `email`)

Keycloak (external IdP) checklist:

1. Create a mail-scoped client (example id: `mail-service`) or add an **Audience** mapper on the Nextcloud client so access tokens include that client in `aud`.
2. In Stalwart: OIDC directory with the same issuer as Nextcloud, `requireAudience` matching that client id, `claimUsername` = `email`.
3. X2Mail domain profile must match the mailbox domain (e.g. `example.com` for `user@example.com`).
4. Optional: `--oidc-audience mail-service` when the login token does not already carry the mail audience (token exchange).

Stalwart Webadmin/Management uses Stalwart’s internal OAuth; external IdP SSO for the admin UI is not supported in current releases — configure mail via OIDC + optional LDAP; use Stalwart’s recovery/fallback admin for server management.

## 5) Troubleshooting Patterns

- `AUTHENTICATIONFAILED` + domain errors:
  - missing/disabled mail domain in Stalwart
- Sieve TLS/auth mismatch:
  - X2Mail `--sieve-ssl` does not match listener mode
- TLS verify failures from Nextcloud:
  - missing CA trust chain for mail certificate issuer
- SMTP temporary auth failure:
  - OIDC validation path broken or audience mismatch

## 6) LDAP/LLDAP Note

LDAP/LLDAP can back mailbox directory lookups, but OAuth auth success still depends
on token validation and identity mapping consistency.
