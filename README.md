# X2Mail -- Nextcloud Webmail with Native XOAUTH2

Feature-rich webmail client for Nextcloud with native Single Sign-On support via XOAUTH2 and OAUTHBEARER. Powered by the SnappyMail engine.

## Quick Start

```bash
# Install SM core
bash scripts/update-core.sh

# Enable the app
occ app:enable x2mail

# Configure with OAUTHBEARER (Keycloak/OIDC)
occ x2mail:setup \
  --imap-host mail.example.com \
  --imap-port 993 --imap-ssl ssl \
  --smtp-host mail.example.com \
  --smtp-port 587 --smtp-ssl tls --smtp-auth \
  --domain example.com \
  --auth oauthbearer \
  --oidc-provider user_oidc

# Or configure with plain password auth
occ x2mail:setup \
  --imap-host mail.example.com \
  --domain example.com \
  --auth plain

# Check status
occ x2mail:status
```

## Features

- Native OAUTHBEARER / XOAUTH2 authentication
- Automatic OIDC token refresh (no session drops)
- Works with `user_oidc` and `oidc_login` NC apps
- Keycloak, Authentik, and any OIDC provider
- Dark mode and responsive design
- Full Sieve filtering support
- Nextcloud integration (Contacts, Files, Calendar, Unified Search)
- Multiple mail accounts and identities
- OpenPGP and S/MIME encryption

## Requirements

- Nextcloud 28 -- 35
- PHP 8.1+
- IMAP server
- Optionally: Keycloak / OIDC provider for SSO

## License

AGPL-3.0 -- see https://www.gnu.org/licenses/agpl-3.0.html
