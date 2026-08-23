# Security Policy

## Supported versions

The `main` branch is the only supported line. When in doubt, update to the latest commit on `main` before reporting an issue.

## Reporting a vulnerability

Please report security vulnerabilities privately via
[GitHub security advisories](https://github.com/MrPunyapal/telescope-inspect/security/advisories/new)
or by emailing **mrpunyapal@gmail.com**.

Do not open public issues for security reports.

You will receive a response within 72 hours with an assessment and next steps.

## Scope notes

This package reads Laravel Telescope's local database storage and prints it to the terminal. Relevant risk areas:

- **Sensitive value exposure**: output may contain application data (URLs, exception messages, model identifiers). Sensitive fields are redacted by default; `--full` intentionally disables redaction. If you find a path where values escape redaction unintentionally, that is a security bug.
- **Known residual exposure by design**: free-text fields are the point of their entries and stay visible without `--full`. That includes SQL statements (which Telescope records with bindings already interpolated), exception and log messages, HTTP-client exception messages that may echo target URLs, mail subjects and sender/recipient addresses, cache keys, IP addresses, and file paths. Treat terminal output as sensitive on production machines.
- **Redis and dumps**: recorded Redis commands show only the verb and first argument by default; dumped variables (`--dumps`) are hidden by default. Both reveal everything with `--full`.
- **No network surface**: the package performs no HTTP calls, has no update mechanism, and ships no external services. The only write it ever performs is Telescope's own monitored-tag list via `telescope:monitor`; entry tables are read-only. Reports of data exfiltration vectors are high priority.
- **SQL injection**: all storage access goes through parameterized query building; string interpolation into SQL would be a critical bug.
- **Serialization**: no PHP object unserialization exists anywhere in this codebase; recorded content is only ever JSON-decoded into plain arrays.

Please include reproduction steps and affected versions. Credit is given in the fix release unless you prefer otherwise.
