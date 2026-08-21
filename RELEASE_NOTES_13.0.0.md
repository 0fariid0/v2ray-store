# V2Ray Store 13.0.0

## Installer and updater

- Fresh installation and update are separate commands.
- Update never runs `apt`, recreates the database, overwrites `baseInfo.php`,
  or requests a new TLS certificate.
- Bot and management-panel deployments are staged, validated, backed up and
  automatically restored if the filesystem swap fails.
- Database migrations run only through PHP CLI; their former public HTTP entry
  points are blocked.
- Cron workers run through local PHP CLI instead of public unauthenticated URLs.

## 3x-ui compatibility

- Current `/panel/api/clients/*` online-client routes are supported.
- Current singular `/panel/api/setting/*` subscription settings routes are
  supported, with older routes retained as fallbacks.
- Client add, update, attach and detach payload contracts remain compatible
  with the current `0fariid0/3x-ui` API.

## Security and reliability

- Telegram webhook requests support and validate `secret_token`.
- Sensitive installer, database and shell files are denied by Apache rules.
- Payment callbacks atomically claim pending payments to stop duplicate order
  creation or duplicate wallet credit.
- Confirmed payments are compensated to the internal wallet if a later bot or
  panel failure prevents fulfilment.
- TLS peer verification is enabled for external HTTPS requests touched by this
  release.
- Generated logs, QR examples, build tools and unreferenced images were removed.

Run `bash tests/run.sh` to lint all PHP and shell files and exercise the staged
update path with an isolated local Git repository.
