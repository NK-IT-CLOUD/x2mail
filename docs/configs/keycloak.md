# Keycloak OIDC Configuration (X2Mail)

This guide defines the IdP side for X2Mail with masked example values only.

## Objective

Issue access tokens that can be accepted by the mail server for SASL
`OAUTHBEARER` / `XOAUTH2` authentication.

## Required Clients

- Nextcloud client: `nextcloud`
- Mail server client (example): `mail-service`

## Required Token Properties

Access token must contain:

- `aud` includes `mail-service` (and optionally `nextcloud`)
- stable user identity (`email` claim recommended)

## Recommended Setup (Audience Mapper)

1. Open Keycloak Admin UI
2. Go to **Clients -> nextcloud -> Client scopes / Mappers**
3. Add **Audience** mapper:
   - Included Client Audience: `mail-service`
   - Add to access token: enabled
4. Ensure `email` claim is present in access token

Expected token excerpt:

```json
{
  "aud": ["nextcloud", "mail-service"],
  "email": "user@example.com"
}
```

## Optional: Token Exchange (Least Privilege)

Instead of adding the mail audience to every login token, X2Mail can exchange
the login token for a mail-scoped token: `--oidc-audience mail-service`,
optionally `--oidc-scopes "mail"` (or the matching setup-wizard fields). The
login token then never works against the mail server — only the exchanged,
narrowly scoped token does.

Requirements with Keycloak 26.2+ (Standard Token Exchange), all on the
Nextcloud client:

- Enable **Standard token exchange** (Capability config)
- Set **Allow refresh token in Standard Token Exchange** to `Same session`
  (the exchange requests a refresh token)
- `offline_access` must not be a *default* client scope — offline sessions
  cannot issue same-session refresh tokens
- The exchange `audience` parameter only *filters* audiences provided by the
  client's scopes; it cannot add them. Put the audience mapper into a
  dedicated **optional client scope** (e.g. `mail`) and request it via
  `--oidc-scopes mail` — then only exchanged tokens carry the mail audience
  and scope
- The login token must contain the Nextcloud client itself in `aud`
  (self-audience mapper)

The exchanged token can be verified in the setup wizard: **Test Login** shows
`TOKEN exchanged for "<audience>"` with the token's `aud`, scopes and
remaining lifetime.

## Network Requirements

- Nextcloud must reach Keycloak for login + refresh
- Mail server must reach Keycloak for introspection/JWKS validation

## Verification

- Login to Nextcloud via OIDC
- In X2Mail wizard, run preflight and check TOKEN output includes expected `aud`
