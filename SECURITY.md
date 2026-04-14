# Security Policy

## Supported Versions

Security fixes are applied to the latest minor release line. Older versions
receive fixes only on a best-effort basis.

| Version | Supported          |
| ------- | ------------------ |
| 5.1.x   | :white_check_mark: |
| < 5.1   | :x:                |

## Reporting a Vulnerability

**Please do not open public GitHub issues for security vulnerabilities.**

If you believe you have found a security issue in Free Form Certificate,
report it privately through GitHub's
[private vulnerability reporting](https://github.com/rpgmem/ffcertificate/security/advisories/new)
form. This creates a confidential advisory visible only to the maintainers.

When reporting, please include:

- A clear description of the issue and its impact.
- Steps to reproduce (proof-of-concept code, request payloads, or a minimal
  WordPress site configuration).
- The plugin version, WordPress version, and PHP version where you observed
  the issue.
- Any suggested mitigation, if known.

You can expect:

- An acknowledgement within a few business days.
- A coordinated disclosure timeline once the issue is triaged.
- Credit in the changelog and the resulting GitHub Security Advisory, unless
  you prefer to remain anonymous.

## Scope

In-scope areas include, but are not limited to:

- Authentication and authorization (magic links, public CSV download hashes,
  ticket-based access, allowlist/denylist enforcement).
- Encryption of sensitive data at rest (email, CPF, IP).
- Form submission handling, CSV export and import, PDF generation.
- Geofencing, rate limiting, captcha, and honeypot bypasses.
- Stored / reflected XSS, SQL injection, CSRF, SSRF, path traversal, and
  insecure direct object references in any plugin endpoint.

Out of scope:

- Vulnerabilities in WordPress core, third-party plugins, or themes.
- Issues that require an already-compromised administrator account.
- Reports based solely on outdated dependencies without a demonstrated
  exploit path through this plugin.
