# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 0.1.x | ✅ |

## Reporting a vulnerability

Please report security vulnerabilities privately via
[GitHub security advisories](https://github.com/MrPunyapal/telescope-inspect/security/advisories/new)
or by emailing **mrpunyapal@gmail.com**.

Do not open public issues for security reports.

You will receive a response within 72 hours with an assessment and next steps.

## Scope notes

This package reads Laravel Telescope's local database storage and prints it to the terminal. Relevant risk areas:

- **Sensitive value exposure** — output may contain application data (URLs, exception messages, model identifiers). Sensitive fields are redacted by default; `--full` intentionally disables redaction. If you find a path where values escape redaction unintentionally, that is a security bug.
- **No network surface** — the package performs no HTTP calls, has no update mechanism, and ships no external services. Reports of data exfiltration vectors are high priority.
- **SQL injection** — all storage access goes through parameterized query building; string interpolation into SQL would be a critical bug.

Please include reproduction steps and affected versions. Credit is given in the fix release unless you prefer otherwise.
