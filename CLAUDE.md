# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

`ai.niktaras.com` is a multi-model AI chat site hosted on shared cPanel hosting (Apache, PHP 7.4, MySQL). The root `index.html` is a model picker; each model is a self-contained app under `models/<name>/` reached at `/models/<name>/index.html`. No package.json, no build step, no framework: plain HTML/CSS/JS frontends and plain PHP backends, deployed as-is by a manual `git push`.

Models today:
- **NKT-1** (`models/NKT-1/`): persona over Google Gemini (`gemini-3.5-flash-lite`).
- **NKT-2L** (`models/NKT-2L/`): persona over Qwen3 14B running in LM Studio on the owner's own PC, reached over the internet via a Tailscale Funnel.

## Architecture

- **Root `index.html`**: the picker. Models are entries in the `MODELS` array in its inline script; each becomes a card whose button navigates to the entry's `url`. Adding a model means adding an entry here and a new `models/<name>/` folder.
- **Each `models/<name>/` is a full copy of the same app**, not shared code: `index.html` (whole frontend: markup, CSS, JS, hand-rolled markdown renderer), `api/` (PHP endpoints), `.htaccess`, own `api/.env`, own `SYSTEM_PROMPT.txt`. Changes to shared behaviour (auth, rate limiting, UI) must be applied to each model's copy by hand; they have already started to diverge in `chat.php`, `config.php`, `SYSTEM_PROMPT.txt` and `.env.example`.
- **Backend flow** (`api/chat.php`): receives `{messages, webAccess, unlockCode}` → rate limit check → one model call → optional Tavily web search via function calling (second model call) → JSON `{reply, sources}`. Anonymous chats are logged to `anonymous_chats`; logged-in users' chats are saved by the frontend calling `api/conversations.php`.
  - NKT-1 calls Gemini `generateContent`.
  - NKT-2L's `call_local_llm()` calls an OpenAI-compatible `POST {LOCAL_LLM_URL}/v1/chat/completions` with `Authorization: Bearer LOCAL_LLM_KEY`. Not streamed (120s timeout).
- **Accounts**: email+password accounts (MySQL, emailed verification link over SMTP), CSRF-protected endpoints. Both models share ONE database (one `users` table; the PHP session cookie is also shared across the site). Accounts are strictly optional: every DB path must fail gracefully so anonymous chat keeps working. Per-model details live in each model's `README.md`.
- **Rate limits are per user per model**: table `user_model_limits (user_id, model, rate_limit NULL, rate_count, rate_window_start)`. `rate_limit` NULL means "use the account default", which is the legacy `users.rate_limit` column. Each model's `chat.php` has `const MODEL_KEY` (and `conversations.php` too) that must equal its name in the admin's `MODELS` list. Anonymous visitors are still limited per browser session at the hard-coded `RATE_LIMIT` (not admin-adjustable). `conversations.model` and `anonymous_chats.model` record which model a chat belongs to; `anonymous_chats` is unique on `(session_id, model)`.
- **Admin panel** is site-wide at `/admin/` (`admin/index.html` + `admin/api/*.php`), not per model. It gets `ADMIN_CODE` and `DB_*` from env vars, then `admin/api/.env` (optional, gitignored), then NKT-1's and NKT-2L's `api/.env`. `ADMIN_CODE` is separate from `UNLOCK_CODE`. It lists users with per-model usage/limits, edits the default and per-model limits, resets usage, deletes accounts, and browses saved and anonymous chats filtered by model.
- **Secrets** live only in `models/<name>/api/.env` on the server (gitignored, never deployed by a push). `api/config.php` reads real env vars first, then `.env`.
- **`.htaccess`** in each model denies web access to `.env*`, `config.php`, `SYSTEM_PROMPT.txt`, `helpers.php`, `db.php`, `auth_helpers.php` and the `.sql` files; any new include-only PHP or SQL file must be added to that list. `admin/.htaccess` does the same for `bootstrap.php`, `.env*` and `*.sql` (covers `admin/api/`). There is deliberately no `.htaccess` at the repo root.
- **Adding a model** also means: add its name to `MODELS` in `admin/api/bootstrap.php`, set `MODEL_KEY` in its `chat.php`/`conversations.php`, and seed `user_model_limits` rows if you want existing users to have explicit rows (not required: missing rows fall back to the default).

## Decisions Made

- **Frontend API paths stay page-relative** (`api/chat.php`, `../../` for the picker link), so the app survives a path change. `og:image`/`og:url` are absolute because scrapers don't resolve relative URLs.
- **PHP version is unclear.** NKT-1's old docs say production ran PHP 7.4 (no `str_contains`, `match`, `?->`, named args, union types), but `chat.php` declares `: never` (PHP 8.1+) and works in production, so the host has since been upgraded or the docs were stale. Confirm the version in cPanel before using newer syntax; until then stay 7.4-safe.
- **Error messages shown to users never name the underlying model or leak server detail**; real detail goes to `error_log` via `$internalDetail` in `fail()`. Personas (NKT-1, NKT-2L) must hold even on errors.
- **NKT-2L runs Qwen3 with thinking off**: the system prompt gets `/no_think` appended in `chat.php`. With thinking on, LM Studio returns reasoning separately in `reasoning_content` and it can consume all of `max_tokens`, producing an empty reply. `strip_reasoning()` also removes any `<think>` blocks.
- **NKT-2L is reached through a Tailscale Funnel** (permanent `*.ts.net` URL, no domain/DNS changes), not a Cloudflare quick tunnel, whose URL changes every restart. The tunnel is locked with LM Studio's "Require Authentication"; the token created for it has per-request and `mcp.json` MCP access set to Deny.
- **NKT-2L is offline whenever the PC or LM Studio server is off**; this is expected and the site just shows "NKT-2L is offline right now".
- **Admin code is separate from the rate-limit unlock code** so unlock-code holders can't read other users' chats.
- **Deploy order for DB changes: run the SQL migration first, then push.** `admin/api/migrate_002_model_limits.sql` is written so the old code keeps working after it runs (new columns have defaults). If the code is pushed first, per-user limits silently fall back to the session limit and saving logged-in chats fails until the migration runs.

## Current State

- Picker, NKT-1 and NKT-2L are deployed and working in production at `ai.niktaras.com`.
- NKT-2L is live: Funnel URL in the server `.env` as `LOCAL_LLM_URL`, `LOCAL_LLM_MODEL=qwen3-14b`, token in `LOCAL_LLM_KEY`.
- Server environment note: the document root `/home/ergotrade/ai.niktaras.com/` must stay `755`; at one point it wasn't, and Apache returned 403 ("unable to check htaccess file"). A stale root `.htaccess` from the pre-restructure NKT-1 deploy was also removed.
- `nkt-1.old/` is a local archive of the old standalone NKT-1 repo, gitignored; can be deleted.
- The site-wide `/admin/` with per-model per-user limits is written and awaiting its first deploy: the old `models/*/admin/` panels and `admin_*.php` endpoints were deleted in the same change. Not yet run against production; `migrate_002_model_limits.sql` must be run in phpMyAdmin before the push.
- No automated tests, linter or CI. There is no PHP on the Windows dev machine, so PHP can't be syntax-checked or run locally.

## What's Left To Do

- Confirm Tailscale is set to start at Windows login so the Funnel survives reboots.
- OPEN (agreed to do later): stream NKT-2L replies (currently the page waits for the full answer). Needs `"stream": true` against LM Studio, SSE forwarding from `chat.php` (cURL write callback, output buffering off), a `fetch` + `getReader()` frontend, and on-the-fly `<think>` filtering. Watch out for the Tavily tool-call flow, rate limiting/logging around a stream, Apache/FastCGI buffering on the shared host, and error messages that must not leak the model name.
- Optional: delete leftover files in the server root (old `api/`, `og-image.JPG`, `.htaccess.bak`) after confirming nothing depends on them. The server-root `admin/` is now the live panel, so keep it. Old per-model `admin/` folders on the server (`models/*/admin/`) and `models/*/api/admin_*.php` are orphaned by the push (git push doesn't delete files on a plain-copy deploy) and can be removed.
- Old `chat` rows have `model = ''` (shown as "Unknown" in the admin); nothing to do unless you want to backfill.
- Shared logic is duplicated per model; consider whether a third model justifies extracting it.

## How To Continue

- **Never stage, commit, or push on your own initiative.** The user commits and pushes manually. Leave changes in the working tree and describe them; only run git write commands if asked in that specific turn.
- Testing is manual: deploy via the user's push, then use the live site. Locally, `php -S localhost:8000` from the repo root works only if PHP is installed, with `curl`, `mbstring` and `pdo_mysql`, and an `api/.env` made from `api/.env.example`.
- To power NKT-2L on: start the LM Studio server (Developer tab) with `qwen3-14b` READY; the Funnel runs in the background. Check it with `tailscale funnel status` and `curl https://niktaras-pc.tail18e753.ts.net/v1/models` (expects 401 without the key, 200 with it). The PC identity is the `niktaras-pc.tail18e753.ts.net` hostname.
- To debug "NKT-2L is offline": check LM Studio server, Tailscale running, then the newest `[NKT-2L chat.php]` line in cPanel → Metrics → Errors (it ends with `curl error: …` or `upstream status …`). A brand-new `.ts.net` hostname can take a few minutes to resolve from the host.
- Gotcha from NKT-1: CSS for modals/forms must be ID/class-scoped, and `.overlay` needs a `[hidden]` override, or the `hidden` attribute is defeated.
