# Changelog

All notable changes to `excelle-insights/email` are documented here.

## [Unreleased]

- Added provider contracts, entities, encrypted sender configuration, provider resolution,
  audit tables and additive adoption migrations (M1).
- Added the send pipeline, versioned templates, suppression checks, status transitions,
  campaign counters and the queue worker (M2).
- Added PHPMailer, Mailgun, Mandrill and log providers with webhook parsers, capability
  flags and secret-safe HTTP audit logging (M3).
- Added campaign queueing/cancellation, normalised webhook events, verified Mailgun and
  Mandrill webhooks, suppression rules, atomic campaign counters and analytics (M4).
- Added the adoption guide in [docs/adoption.md](docs/adoption.md).
- Added the usage guide in [docs/usage.md](docs/usage.md) and completed local
  v1.0.0 release verification (M5).
- Fixed MySQL migration detection of a pre-existing shared `http_request_logs`
  table.
- Implementation milestones are tracked in [docs/milestones.md](docs/milestones.md).
