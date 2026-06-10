# Dovecot + Postfix with OAUTHBEARER/XOAUTH2

Masked reference configuration for using X2Mail with Dovecot IMAP and Postfix
submission, where token validation is handled by Dovecot OAuth2.

## Scope

- IMAP auth: Dovecot (`OAUTHBEARER` / `XOAUTH2`)
- SMTP submission auth: Postfix via Dovecot SASL socket
- Optional Sieve: Dovecot ManageSieve

## 1) Dovecot OAuth2 Passdb

`/etc/dovecot/dovecot-oauth2.conf.ext`:

```ini
introspection_mode = post
introspection_url  = https://idp.example.com/realms/example/protocol/openid-connect/token/introspect
client_id          = mail-service
client_secret      = <secret>
username_attribute = email
```

Optional hardening (Dovecot 2.4+): require a dedicated scope so only
exchanged/mail-scoped tokens can authenticate — any other valid IdP token
(e.g. a plain login token) is rejected with `insufficient_scope`. Pair this
with the token-exchange setup in [keycloak.md](keycloak.md):

```ini
scope = mail
```

`/etc/dovecot/conf.d/10-auth.conf` (excerpt):

```ini
auth_mechanisms = $auth_mechanisms xoauth2 oauthbearer

passdb {
  driver = oauth2
  mechanisms = xoauth2 oauthbearer
  args = /etc/dovecot/dovecot-oauth2.conf.ext
}
```

## 2) Postfix Submission via Dovecot SASL

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

## 3) X2Mail Setup Example

```bash
occ x2mail:setup \
  --imap-host mail.example.com \
  --imap-port 143 --imap-ssl starttls \
  --smtp-host mail.example.com \
  --smtp-port 587 --smtp-ssl starttls \
  --domain example.com \
  --sieve --sieve-port 4190 --sieve-ssl starttls
```

## 4) Verify Capabilities

Check IMAP capabilities include OAuth SASL:

```bash
openssl s_client -connect mail.example.com:143 -starttls imap -quiet
# then type: a1 CAPABILITY
```

Check SMTP AUTH list includes OAuth SASL:

```bash
openssl s_client -connect mail.example.com:587 -starttls smtp -quiet
# then type: EHLO test
```

Look for `AUTH ... OAUTHBEARER ... XOAUTH2`.

## 5) Common Failures

- Missing audience (`aud`) for mail client in token
- Mail server cannot reach IdP introspection endpoint
- Token has no usable identity claim (`email`)
- TLS trust mismatch between Nextcloud and mail endpoint cert chain
