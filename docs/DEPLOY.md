# Deploying and switching over (Phase 10)

How the Laravel API goes live on Render and takes over from the Node API.
Nothing here has been run yet; each step says who does it.

## What runs where

| Render service | What it is | From |
|---|---|---|
| `event-api` | the API (`/api/v1/*`), health check `/up` | `Dockerfile`, `APP_ROLE=web` |
| `event-worker` | background jobs (push, e-mail, translations) | same image, `APP_ROLE=worker` |
| `event-scheduler` | due messages and notices, every minute | same image, `APP_ROLE=scheduler` |
| `event-chat` | chat relay (socket.io), holds no data | `chat-socket/` |
| `event-redis` | cache, queue, locks | Render Key Value |

All five are in `render.yaml` (a Render Blueprint). Secrets are left blank there;
Render asks for them on the first sync.

The database is a **new MySQL database** (for example a second database on the
same Aiven server, `event_v2`). The Node database is only **read**, once, by the import.
Media stay where they are (S3 + CloudFront): no files move.

## 1. Before the day (developer)

1. Put this folder under git and push it to GitHub (it is not a repository yet).
2. Create the new database on Aiven (`event_v2`), empty.
3. Work out the secrets:
   - `APP_KEY`: `php artisan key:generate --show`
   - `EVENT_QR_KEY`: from the secret the Node API uses on Render (`EVENT_QR_SECRET`,
     or `ACCESS_TOKEN_SECRET` if that is not set):
     `node -e "console.log(require('crypto').scryptSync('<secret>','event-qr-v1',32).toString('base64'))"`
     — a wrong key means printed invitations stop opening. The import test checks every stored code.
   - `CHAT_SOCKET_SECRET`: any long random string; the same value on `event-api` and `event-chat`.
   - The rest are copied from the Node service's environment (AWS, MSG91, Google/Facebook, MyMemory).
4. Render → **New → Blueprint** → this repository. Fill the secrets. `DB_*` = the new
   database, `LEGACY_DB_*` = the Node database, `DB_SSL=true`, `LEGACY_DB_SSL=true`.
   `FRONTEND_URL` = every frontend origin, comma separated. `CHAT_SOCKET_URL` = event-chat's
   URL; on event-chat, `LARAVEL_URL` = event-api's URL.
5. First deploy: migrations run on start (`RUN_MIGRATIONS=true`). Then, in the event-api
   **Shell**: `php artisan db:seed --force` (house company / vendor, permission catalogues).
6. Rehearse: `php artisan legacy:import --dry-run --report=/tmp/report.txt`, read the report.
7. Point a **copy** of one frontend (a Vercel preview) at the new API and click through it.

## 2. The switch (about 30 minutes, announce it)

1. Tell users the system will be unavailable for a short while.
2. Stop writes on the old system: turn off the Node service's write traffic (simplest:
   suspend the frontends with a maintenance page, or suspend the Node service at the end).
3. In the event-api Shell: `php artisan legacy:import --report=/tmp/import.txt` (≈5 minutes).
4. Check: sign in to admin, vendor, staff and client portals with real accounts; open an
   event by its QR; open the public site; send a chat message.
5. Point every app at the new API — **Vercel env vars, then redeploy each**:

   | App | Variable |
   |---|---|
   | Admin frontend | `NEXT_PUBLIC_API_URL` |
   | Vendor frontend | `NEXT_PUBLIC_API_URL` |
   | Staff portal | `NEXT_PUBLIC_API_URL` |
   | Client portal | `NEXT_PUBLIC_API_URL` (and its proxy target) |
   | Website builder | `NEXT_PUBLIC_API_URL` |
   | Public site | `NEXT_PUBLIC_API_URL` |
   | Chat socket URL in the portals | the event-chat URL |

6. **The mobile app** has the API address built in (`lib/core/config/app_config.dart`,
   default `https://event-management-admin-backend.onrender.com/api/v1`). Installed copies
   keep calling that address. Choose one before the day:
   - **A. Keep the address:** deploy this image into the existing Render service
     `event-management-admin-backend` (switch it to Docker, this repository). Old app copies
     keep working with no app update. The old Node code stops running (rollback = redeploy it).
   - **B. New address:** build and publish an app update with
     `--dart-define=API_BASE_URL=<new url>`; old copies stop working until users update.
   - Recommended for later either way: a domain of your own (`api.<your-domain>`) so the next
     move is a DNS change, not an app release.
7. Everyone signs in again (sessions are not copied).

## 3. If something is wrong (rollback)

The import only reads the Node database, so it is unchanged: point the apps back at the
Node URL (or redeploy the Node code into the service, option A). Anything written to the new
API after the switch is not in the old database.

## Settings worth knowing

- `RUN_MIGRATIONS=true` on event-api runs pending migrations on every start; that is
  intended (migrations are additive). Set it to `false` if you prefer running them by hand.
- `TRUSTED_PROXIES` defaults to all (Render's load balancer); visitors' IPs and https come
  from the forwarded headers.
- Logs go to the Render log stream (`LOG_CHANNEL=stderr`).
- `FFMPEG_PATH` is set in the image (video thumbnails and muting work).
