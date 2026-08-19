# TBT Login Redirect — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task; do not scan the whole repository by default.
- Check `git status`, use targeted search, and read only the relevant function/README section.
- The plugin lives under `tbt-login-redirect/`; main file is `tbt-login-redirect/tbt-login-redirect.php`.
- Do not change unrelated login behavior, restriction detection, cookies, redirect precedence, or filters.
- Inspect the final diff before finishing.

## Project purpose

- WordPress plugin for The Blue Tree.
- It fixes one narrow flow: a logged-out visitor following a direct link to a restricted lesson should be sent to login and then returned to that exact lesson.
- It intentionally leaves Addify role-based redirects and WooCommerce Memberships restriction settings untouched.
- Normal logins that did not originate from this restricted-lesson flow must keep their existing redirect behavior.
- The plugin is designed to fail safe when it cannot confidently identify a lesson/restricted request.

## Compatibility boundaries

- Keep lesson post types, URL-pattern detection, login URL, and related site-dependent decisions filterable rather than hardcoding new live-site assumptions.
- Preserve the short-lived cookie/query-flag mechanism used to carry the requested same-site lesson through login forms that may drop `redirect_to`.
- Do not broaden interception to ordinary 404s, arbitrary URLs, wp-admin, logout, or unrelated login flows without an explicit task.
- Do not modify Addify or WooCommerce Memberships behavior from this plugin unless the task explicitly changes the integration contract.

## Redirect and security rules

- Redirect targets must remain same-site and validated; never create an open redirect.
- Treat request paths, query parameters, cookies, and filter returns as untrusted input.
- Preserve secure/safe redirect helpers and sanitization appropriate to URLs/cookies.
- Avoid redirect loops: login pages and the return target must not continuously re-trigger interception.
- Never commit credentials, production cookies, personal session data, secrets, or local configuration.

## Coding style

- Follow the surrounding WordPress/PHP style; do not reformat the whole ~16 KB plugin for a focused change.
- Prefer small local changes and existing filters/helpers over parallel redirect systems.
- Keep comments explaining ordering/priority and compatibility decisions where timing against other plugins matters.
- Do not add a framework, database table, or front-end assets for a task that can remain a small redirect plugin.

## Validation

Run:

```bash
php -l tbt-login-redirect/tbt-login-redirect.php
```

For redirect changes, reason through at least these flows:
- logged-out direct restricted lesson → login → requested lesson;
- ordinary login → existing normal destination;
- logged-in lesson request → no interception;
- unrelated 404/public URL → no interception;
- malformed/off-site redirect candidate → rejected;
- missing WooCommerce/Addify-related functions → graceful fallback/no fatal.

Live-site verification is required when hook priority or membership/login integration changes because the behavior depends on installed plugins and real request routing.

## Git

- `main` is the integration branch. It was created from the original working Claude implementation branch without changing code.
- Use a focused feature branch for future changes; do not base new work on `claude/specs-implementation-i98pow`.
- No deployment workflow is assumed by this guidance; verify current deployment practice before adding or changing automation.

## Context discipline

- Prefer targeted search + narrow reads over broad exploration.
- Read `tbt-login-redirect/README.md` only when the task needs the detailed flow/diagnosis or filter documentation.
- Do not paste entire request logs, cookies, or source files into the conversation when a focused excerpt is enough.
- Finish with a brief summary of changes, redirect cases checked, and any live integration verification still needed.
- For a new unrelated task, prefer a fresh Claude Code session.
