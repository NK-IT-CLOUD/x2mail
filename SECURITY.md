# Security Policy

## Supported versions

Security fixes are released for the latest 0.8.x version only. Please upgrade to the
latest release; older versions do not receive fixes.

| Version        | Supported |
|----------------|-----------|
| 0.8.6 or later | Yes       |
| < 0.8.6        | No        |

Published advisories are listed under
[Security advisories](https://github.com/NK-IT-CLOUD/x2mail/security/advisories).

## Reporting a vulnerability

Please report security vulnerabilities privately by email:

**security@nk-it.cloud**

Do not open a public issue for security vulnerabilities.

We aim to respond within 48 hours. For critical issues we aim to release a fix within 7 days.

## Security measures

- All admin endpoints require Nextcloud admin authentication
- Every webmail action requires the request token; actions that change data are accepted
  only as POST requests
- Message HTML is sanitized before display; remote content loads only after the user
  allows it
- The mail account is the email address verified at single sign-on, not the editable
  Nextcloud profile email
- No stored mail credentials: X2Mail only uses the SSO token from the Nextcloud session
- S/MIME certificates are trusted only after an explicit import by the user
- Path traversal prevention on all file operations
- Hostname validation on IMAP/SMTP configuration
- Exception messages are logged on the server and never sent to the browser
- Rate limiting on setup wizard preflight checks
