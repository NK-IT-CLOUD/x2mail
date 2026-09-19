# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.8.x   | Yes       |
| 0.7.x   | Yes       |
| < 0.7   | No        |

## Reporting a vulnerability

Please report security vulnerabilities privately by email:

**nk@dev.nk-it.cloud**

Do not open a public issue for security vulnerabilities.

We aim to respond within 48 hours. For critical issues we aim to release a fix within 7 days.

## Security measures

- All admin endpoints require Nextcloud admin authentication
- CSRF protection via Nextcloud AppFramework
- Path traversal prevention on all file operations
- Hostname validation on IMAP/SMTP configuration
- No stored mail credentials: X2Mail only uses the SSO token from the Nextcloud session
- Exception messages are logged on the server and never sent to the browser
- Rate limiting on setup wizard preflight checks
