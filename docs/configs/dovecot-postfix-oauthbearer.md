# Dovecot + Postfix with OAUTHBEARER/XOAUTH2

Reference configuration for X2Mail with Dovecot IMAP and Postfix submission.
Dovecot 2.4 validates the tokens with its OAuth2 settings. All values are masked
examples.

## Scope

- IMAP auth: Dovecot (`OAUTHBEARER` / `XOAUTH2`)
- SMTP submission auth: Postfix via Dovecot SASL socket
- Optional Sieve: Dovecot ManageSieve

## 1) Dovecot OAuth2 settings

This section uses the Dovecot 2.4 configuration format. Since 2.4, the
`oauthbearer` and `xoauth2` mechanisms validate tokens with the global
`oauth2` settings directly and no longer need an `oauth2` passdb.

`/etc/dovecot/conf.d/10-auth.conf` (excerpt):

```ini
auth_mechanisms {
  oauthbearer = yes
  xoauth2 = yes
}

oauth2 {
  introspection_mode = post
  introspection_url  = https://idp.example.com/realms/example/protocol/openid-connect/token/introspect
  client_id          = mail-service
  client_secret      = <secret>
  username_attribute = email
}
```

`dovecot.conf` must begin with a `dovecot_config_version` line (the 2.4
packages ship it). Configurations in the Dovecot 2.3 format
(`passdb { driver = oauth2 }` with a separate `dovecot-oauth2.conf.ext`) are
not accepted by Dovecot 2.4.

Optional hardening: require a dedicated scope so that only exchanged,
mail-scoped tokens can authenticate. Dovecot then rejects any other valid IdP
token (e.g. a plain login token) with `insufficient_scope`. Add it to the
`oauth2` block and combine it with the token exchange setup in
[keycloak.md](keycloak.md):

```ini
oauth2 {
  # ... settings from above ...
  scope = mail
}
```

## 2) Postfix submission via Dovecot SASL

`/etc/postfix/master.cf` (submission service):

```ini
submission inet n - y - - smtpd
  -o smtpd_tls_security_level=encrypt
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_sasl_type=dovecot
  -o smtpd_sasl_path=private/auth
  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject
```

Dovecot SASL socket for Postfix (`/etc/dovecot/conf.d/10-master.conf` excerpt):

```ini
service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}
```

## 3) X2Mail setup example

```bash
occ x2mail:setup \
  --imap-host mail.example.com \
  --imap-port 143 --imap-ssl starttls \
  --smtp-host mail.example.com \
  --smtp-port 587 --smtp-ssl starttls \
  --domain example.com \
  --sieve --sieve-port 4190 --sieve-ssl starttls
```

## 4) Verify capabilities

Check that the IMAP capabilities include OAuth SASL:

```bash
openssl s_client -connect mail.example.com:143 -starttls imap -quiet
# then type: a1 CAPABILITY
```

Check that the SMTP AUTH list includes OAuth SASL:

```bash
openssl s_client -connect mail.example.com:587 -starttls smtp -quiet
# then type: EHLO test
```

Look for `AUTH ... OAUTHBEARER ... XOAUTH2`.

## 5) Common failures

- The token's `aud` does not contain the mail client
- The mail server cannot reach the IdP introspection endpoint
- The token has no usable identity claim (`email`)
- Nextcloud does not trust the certificate chain of the mail endpoint
