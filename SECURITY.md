# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.6.x   | Yes       |
| < 0.6   | No        |

## Reporting a Vulnerability

Please report security vulnerabilities privately via email:

**nk@dev.nk-it.cloud**

Do not open a public issue for security vulnerabilities.

We aim to respond within 48 hours and release a fix within 7 days for critical issues.

## Security Measures

- All admin endpoints require Nextcloud admin authentication
- CSRF protection via Nextcloud AppFramework
- Path traversal prevention on all file operations
- Hostname validation on IMAP/SMTP configuration
- No credential storage — SSO tokens from Nextcloud session only
- Exception messages are logged server-side, never exposed to browser
- Rate limiting on setup wizard preflight checks
