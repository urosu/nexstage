# Secret Rotation Procedure

All secrets live in `.env` (local) or the Hetzner server environment (production).
The rule: **rotate first, revoke old, verify, document**.

---

## Inventory of Secrets

| Secret                        | Where set                    | Rotation frequency   |
|-------------------------------|------------------------------|----------------------|
| `APP_KEY`                     | `.env`                       | On compromise only   |
| `DB_PASSWORD`                 | `.env` + DB cluster settings | Every 6 months       |
| `REDIS_PASSWORD`              | `.env` + Redis config        | Every 6 months       |
| `STRIPE_KEY` / `STRIPE_SECRET`| `.env`                       | On compromise only   |
| `STRIPE_WEBHOOK_SECRET`       | `.env` + Stripe Dashboard    | On compromise only   |
| `FACEBOOK_APP_ID` / `SECRET`  | `.env`                       | On compromise only   |
| `GOOGLE_CLIENT_ID` / `SECRET` | `.env`                       | On compromise only   |
| `PSI_API_KEY`                 | `.env`                       | On compromise only   |
| `HORIZON_BASIC_AUTH_PASSWORD` | `.env`                       | Every 6 months       |
| ngrok auth token              | ngrok account                | On compromise only   |
| SSH deploy key                | Server + GitHub              | Every 12 months      |

---

## General Rotation Steps

1. **Generate the new secret** (never reuse).
   - `APP_KEY`: `php artisan key:generate --show`
   - Random secrets: `openssl rand -base64 40`

2. **Set the new value** on the target environment:
   - Local: update `.env`
   - Production: update the environment variable in the Hetzner server panel (or SSH `export`)

3. **Deploy** (or restart PHP/Horizon to pick up the new value):
   ```bash
   docker restart nexstage-php nexstage-horizon
   ```

4. **Verify** the application is healthy:
   - `/admin/system-health` — confirm no queue failures
   - `/admin/logs` — confirm no authentication errors

5. **Revoke the old secret** in the issuing system (Stripe Dashboard, Google Cloud Console, etc.)
   only after step 4 confirms the new one works.

6. **Update this document's last-rotated column.**

---

## `APP_KEY` Rotation

`APP_KEY` is used for cookie and session encryption. Rotating it **invalidates all active sessions**.
Users will be logged out. Do this during low-traffic periods.

```bash
php artisan key:generate
# Update .env APP_KEY=base64:...
docker restart nexstage-php nexstage-horizon
```

---

## Database Password Rotation

1. Create new DB user or update password in Hetzner Managed Databases panel.
2. Update `DB_PASSWORD` in the server environment.
3. Restart containers: `docker restart nexstage-php nexstage-horizon`.
4. Verify: `docker exec nexstage-php php artisan tinker --execute="DB::select('SELECT 1');"`.
5. Remove old user/password from the DB cluster.

---

## Stripe Webhook Secret Rotation

1. In Stripe Dashboard → Webhooks → select the Nexstage endpoint → Reveal signing secret → Roll secret.
2. Copy the new `whsec_...` value.
3. Update `STRIPE_WEBHOOK_SECRET` in the server environment.
4. Restart containers.
5. Verify next webhook delivery succeeds in `/admin/logs` (webhook tab).

---

## Facebook / Google OAuth Secret Rotation

Facebook and Google app secrets are long-lived but must be rotated on suspected compromise.

**Facebook:**
1. Meta Developer Portal → App → Settings → Basic → App Secret → Reset.
2. Update `FACEBOOK_APP_SECRET` in `.env`.
3. Existing long-lived user tokens are tied to the app — rotating the secret invalidates them.
4. All connected workspaces will need to re-authenticate via `/oauth/facebook`.

**Google:**
1. Google Cloud Console → Credentials → OAuth 2.0 Client → select → Reset Secret.
2. Update `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` in `.env`.
3. Refresh tokens are not immediately invalidated, but re-auth should be prompted on next sync.

---

## Rotation Log

| Date       | Secret rotated           | Reason     | Verified by |
|------------|--------------------------|------------|-------------|
| _(pending)_ | All secrets set at launch | Initial setup | —        |
