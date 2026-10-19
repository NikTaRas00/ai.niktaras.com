# NKT-1

A lightweight chat interface for NKT-1, a model developed by the NikTaras AI division of NikTaras Web Studio, with optional live web search.

**Live:** https://ai.niktaras.com/NKT-1/

## Features

- Single-file frontend (`index.html`) — no build step, no framework, no dependencies.
- PHP backend proxy (`api/chat.php`) that talks to the Gemini API under the hood, keeping the API key server-side.
- Optional live web search via [Tavily](https://tavily.com) (free tier, 1,000 searches/month), toggled per-message with the "Web Access" button next to the prompt input and wired up through Gemini function calling.
- Markdown rendering and source citations in the chat UI.
- Optional user accounts (email + password, email verification required) backed by MySQL. Chat works fully anonymously either way — logging in just adds saved chat history.
- Rate limit of 5 messages/hour by default. Logged-in accounts get a persistent, admin-adjustable limit; anonymous visitors are limited per browser session. Past the limit, an access code (`UNLOCK_CODE` in `.env`) unlocks exactly one more message at a time.
- A minimal admin panel at `/admin/` (gated by its own `ADMIN_CODE`) to browse registered accounts, view/edit their rate limit, read their saved chat history, and review anonymous (signed-out) chats.

## Project structure

```
.
├── index.html            # Frontend: markup, styles, and JS in one file
├── og-image.JPG           # Social preview image
├── .htaccess              # Blocks direct access to secrets/config over HTTP
└── api/
    ├── chat.php            # Backend endpoint the frontend calls
    ├── config.php           # Loads secrets from .env or server env vars
    ├── helpers.php           # Shared load_config() + verification email sender
    ├── db.php                # PDO MySQL connection
    ├── auth_helpers.php      # Session bootstrap, CSRF, current-user helpers
    ├── register.php          # POST: create account, email a verification link
    ├── verify.php            # GET: confirms the emailed verification link
    ├── login.php             # POST: authenticates, starts a session
    ├── logout.php            # POST: ends the session
    ├── me.php                # GET: current session state + CSRF token
    ├── conversations.php     # GET/POST/DELETE: saved chat history (requires login)
    ├── admin_me.php           # GET: admin session state + CSRF token
    ├── admin_login.php        # POST: enter the admin panel with ADMIN_CODE
    ├── admin_logout.php       # POST: leaves the admin panel
    ├── admin_users.php        # GET/POST: list/view accounts, edit rate limits
    ├── admin_conversation.php # GET: any account's saved chat, in full
    ├── admin_anon_chats.php   # GET: anonymous chats (list + full)
    ├── schema.sql             # MySQL tables (fresh installs)
    ├── migrate_001_admin.sql  # ALTER/CREATE statements for an existing DB
    ├── .env.example          # Template for required environment variables
    └── SYSTEM_PROMPT.txt     # System prompt sent to Gemini
admin/
└── index.html                # Admin panel: accounts, rate limits, anonymous chats
```

## Setup

Requires PHP with the `curl`, `mbstring`, and `pdo_mysql` extensions.

1. Clone the repo into your web server's document root (or a subdirectory of it).
2. Copy `api/.env.example` to `api/.env` and fill in your keys:
   ```
   GEMINI_API_KEY=your-gemini-api-key-here
   TAVILY_API_KEY=your-tavily-api-key-here   # optional, enables web search
   ```
   `api/.env` is never committed — see `.htaccess`, which also blocks direct HTTP access to it.
3. Serve the directory with Apache (or any server that honors `.htaccess` and runs PHP). Open `index.html` in a browser.

### Enabling accounts (optional)

Chat works fully signed out without any of this. To enable accounts and saved chat history:

1. In cPanel, create a MySQL database and a database user (MySQL Databases page), and note the host/name/user/password.
2. Run `api/schema.sql` against that database (e.g. via phpMyAdmin's SQL tab) to create the tables. If you already ran an older version of `schema.sql` on this database, run `api/migrate_001_admin.sql` instead (adds the columns/table the admin panel needs).
3. Fill in the `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`, `SITE_URL`, and `MAIL_FROM` keys in `api/.env` (see `api/.env.example`).
4. Verification emails are sent with PHP's built-in `mail()` — no SMTP setup needed, but deliverability depends on your host's mail configuration and your domain's SPF/DKIM records.

### Admin panel

Visit `/admin/` and enter your `ADMIN_CODE` (set in `.env`, separate from `UNLOCK_CODE`) to view accounts, edit a user's messages/hour limit, read their saved chats, and browse anonymous chats. It needs the database set up (above) — without it, the panel loads but every tab shows "The database isn't set up on this server yet." Keep `ADMIN_CODE` to yourself — it grants full read access to every account's and every anonymous visitor's chat history.

### Deploying to a subdirectory

The frontend calls the backend with a page-relative path (`api/chat.php`), so it works from whatever subdirectory you deploy it to — no code changes needed. The Open Graph tags in `index.html` (`og:image`, `og:url`) are absolute and should be updated if you deploy somewhere other than `https://ai.niktaras.com/NKT-1/`.

## How it works

1. The browser posts the conversation history to `api/chat.php`, along with whether "Web Access" is toggled on.
2. `chat.php` forwards it to the Gemini API (`gemini-3.5-flash-lite`) along with the system prompt from `api/SYSTEM_PROMPT.txt`, which instructs the model to present itself as NKT-1 rather than as Gemini.
3. If Web Access is on (and a Tavily key is configured) and the model requests a search, `chat.php` calls Tavily, feeds the results back to the model, and returns the final answer with cited sources.
