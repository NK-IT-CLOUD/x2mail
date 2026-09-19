# Keycloak OIDC configuration (X2Mail)

This guide covers the IdP side of an X2Mail setup. All values are masked examples.

## Objective

Keycloak must issue access tokens that the mail server accepts for SASL
`OAUTHBEARER` / `XOAUTH2` authentication.

## Required clients

- Nextcloud client: `nextcloud`
- Mail server client (example): `mail-service`

## Required token properties

The access token must contain:

- `aud` includes `mail-service` (and optionally `nextcloud`)
- stable user identity (`email` claim recommended)

## Recommended setup (audience mapper)

1. Open Keycloak Admin UI
2. Go to **Clients -> nextcloud -> Client scopes / Mappers**
3. Add **Audience** mapper:
   - Included Client Audience: `mail-service`
   - Add to access token: enabled
4. Make sure the access token contains the `email` claim

Expected token excerpt:

```json
{
  "aud": ["nextcloud", "mail-service"],
  "email": "user@example.com"
}
```

## Optional: token exchange (least privilege)

Instead of adding the mail audience to every login token, X2Mail can exchange
the login token for a mail-scoped token: `--oidc-audience mail-service`,
optionally `--oidc-scopes "mail"` (or the matching setup wizard fields). The
mail server then rejects the login token and accepts only the exchanged,
narrowly scoped token.

Requirements with Keycloak 26.2+ (Standard Token Exchange), all on the
Nextcloud client:

- Enable **Standard token exchange** (Capability config)
- Set **Allow refresh token in Standard Token Exchange** to `Same session`
  (the exchange requests a refresh token)
- `offline_access` must not be a *default* client scope, because offline
  sessions cannot issue same-session refresh tokens
- The exchange `audience` parameter only *filters* audiences provided by the
  client's scopes; it cannot add them. Put the audience mapper into a
  dedicated **optional client scope** (e.g. `mail`) and request it via
  `--oidc-scopes mail`. Then only exchanged tokens carry the mail audience
  and scope
- The login token must contain the Nextcloud client itself in `aud`
  (self-audience mapper)

To verify the exchanged token, run **Test Login** in the setup wizard. It shows
`TOKEN exchanged for "<audience>"` with the token's `aud`, scopes and
remaining lifetime.

## Network requirements

- Nextcloud must reach Keycloak for login and token refresh
- The mail server must reach Keycloak for introspection/JWKS validation

## Verification

- Log in to Nextcloud via OIDC
- In the X2Mail wizard, run the preflight and check that the TOKEN line shows the expected `aud`
