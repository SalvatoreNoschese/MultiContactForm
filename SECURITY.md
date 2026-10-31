# Security Policy

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Use GitHub's private Security Advisories form (the repository's **Security**
tab → **Report a vulnerability**). It opens a channel only you and the
maintainer can see.

Include what you found, how to reproduce it, and what an attacker gains. A proof
of concept helps but is not required.

The project is maintained by one person in their spare time, so a reply may not
be immediate. If two weeks pass with no response, assume the message was lost
rather than ignored, and ping publicly without disclosing details.

## Supported versions

Only the latest release receives security fixes.

## In scope

- Sending mail to any address other than `mail.to` (the form never writes to
  the address the visitor types), or injecting headers into a message.
- Bypassing CSRF, the origin check, the proof-of-work (including replaying a
  solution), the honeypot, the minimum fill time or the rate limits in a way
  that makes bulk sending practical.
- Locking the form for everybody from a single client.
- XSS, script execution despite the CSP, or an attachment whose real type
  differs from the one accepted.
- Reaching anything outside `public/` over HTTP, in either layout.
- Anything stored or logged that the privacy page says is not: raw IP
  addresses, message contents, attachments.

## Out of scope

- Attacks that assume the server or the mailbox is already compromised.
- Spam that solves the proof-of-work and stays within the rate limits: the
  proof-of-work makes bulk sending costly, it is not meant to stop a person.
- Vulnerabilities in the vendored ALTCHA code that are already fixed upstream
  (see README.md, "Third-party code", to update).

## Accepted risks

- **Rate limits are per client IP.** Behind a proxy that hides it, set
  `client_ip` correctly (header and trusted proxies, as addresses or CIDR
  ranges), or every visitor shares one limit.
- **The global rate limit** can be used up by someone who solves the
  proof-of-work repeatedly from many addresses: it protects your mailbox, at
  the cost of the form being unavailable until the window passes.
