# Security policy

Security fixes are developed on `main`. No stable release series or long-term
support policy has been established yet; use the latest reviewed revision and
monitor repository updates.

## Reporting a vulnerability

Do not post credentials, private API responses, route coordinates or exploit
details in a public issue. Use GitHub's **Security → Report a vulnerability** for
this repository when private vulnerability reporting is enabled. If that option
is unavailable, contact the SavvyCodes repository maintainers privately through
your existing support channel and request a secure reporting route first.

Include the plugin revision, PHP/Kirby versions, impact, and a minimal reproduction
with synthetic data. No response-time commitment is currently offered.

## Operator responsibilities

- Keep API credentials outside Git and the web root; rotate leaked credentials.
- Protect cache/backup directories: cached access tokens are sensitive.
- This plugin exposes unauthenticated JSON project/contact information. Verify
  publication rights and the fields exposed before enabling it.
- Add rate limiting and privacy disclosure when exposing live Traveler helpers.
- Do not enable Kirby debug output on public sites. Sanitize output in templates.
- Monitor refresh failures; last-known-good cached data can become stale.

Before publication, maintainers must enable private vulnerability reporting.
Making this repository public does not grant
access to the Omleidingennet API or a license to republish third-party data.
