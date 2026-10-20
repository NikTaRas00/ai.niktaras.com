# NKT-2L

Same chat UI, accounts and rate limits as NKT-1 (admin is the site-wide panel at
`/admin/`), but the model is
**Qwen3 14B running in LM Studio on the owner's PC**. The PHP backend
(`api/chat.php`) proxies to LM Studio's OpenAI-compatible API through a
Cloudflare Tunnel. When the PC is off, chat returns "NKT-2L is offline".

## PC setup

1. In LM Studio, download **Qwen3 14B** and load it. Open the Developer tab,
   start the server (default `http://localhost:1234`) and note the model
   identifier it shows (e.g. `qwen/qwen3-14b`).
2. Install `cloudflared` and expose the server:
   `cloudflared tunnel --url http://localhost:1234` for a throwaway URL, or
   create a named tunnel with a hostname such as `llm.niktaras.com` for a
   permanent one (run it as a service so it survives reboots).
3. Optional but recommended: require a key so strangers who find the tunnel URL
   can't use your GPU. Put the same value in `LOCAL_LLM_KEY` below and enforce
   it at the tunnel (Cloudflare Access service token) since LM Studio has no
   built-in key check.

## Server setup

Create `api/.env` (see `api/.env.example`): set `LOCAL_LLM_URL`,
`LOCAL_LLM_MODEL`, `SITE_URL=https://ai.niktaras.com/models/NKT-2L`, plus the
DB/SMTP/UNLOCK_CODE/ADMIN_CODE values (can match NKT-1's). Apply
`api/schema.sql` to a database; reusing NKT-1's database works, but then
accounts and chats are shared between both models.

Replies are not streamed yet; the page waits for the full answer (120s limit).
