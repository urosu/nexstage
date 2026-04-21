# Database Backups — PITR + Test Restore

## Strategy

Nexstage uses **Point-in-Time Recovery (PITR)** via managed PostgreSQL backups. The primary
database must never be the only copy of production data.

---

## Enabling PITR

### Hetzner Managed Databases (current setup)

1. In the Hetzner Cloud Console → Databases → select the Nexstage database cluster.
2. Under **Backups**, confirm that automated daily backups are enabled.
3. Retention is 7 days by default — set to **14 days** for safety during beta.
4. PITR (continuous WAL archiving) requires the "Dedicated" plan or higher; confirm the plan
   tier supports it.

### Alternative: `pg_basebackup` + WAL archiving (self-hosted)

If running PostgreSQL in a Docker container (e.g. `nexstage-db`):

```bash
# Daily base backup — run as a cron job on the host
docker exec nexstage-db \
  pg_basebackup -U postgres -D /var/lib/postgresql/backups/$(date +%Y%m%d) -Fp -Xs -P -R

# Copy offsite — example: rsync to a Hetzner Storage Box
rsync -az /path/to/backups/ user@storage-box:/nexstage-backups/
```

WAL archiving must be enabled in `postgresql.conf`:

```
wal_level = replica
archive_mode = on
archive_command = 'cp %p /var/lib/postgresql/wal_archive/%f'
```

---

## Test Restore Procedure

Run a test restore every 90 days and record the result here.

### Steps

1. **Provision a fresh PostgreSQL instance** (local Docker or separate cloud VM).

2. **Restore base backup**:
   ```bash
   docker run -d \
     --name nexstage-restore-test \
     -e POSTGRES_PASSWORD=testpassword \
     -v /path/to/backup/YYYYMMDD:/var/lib/postgresql/data \
     postgres:16
   ```

3. **Verify schema integrity**:
   ```bash
   docker exec nexstage-restore-test \
     psql -U postgres -d nexstage -c "\dt"
   ```

4. **Verify row counts** match production:
   ```bash
   docker exec nexstage-restore-test psql -U postgres -d nexstage -c "
     SELECT
       (SELECT COUNT(*) FROM orders)      AS orders,
       (SELECT COUNT(*) FROM workspaces)  AS workspaces,
       (SELECT COUNT(*) FROM ad_insights) AS ad_insights;
   "
   ```

5. **Run Laravel migrations** against the restored DB to confirm no schema drift:
   ```bash
   # Point DB_HOST to the restore container temporarily
   docker exec nexstage-php php artisan migrate --database=pgsql_restore
   ```
   Expected output: `Nothing to migrate.`

6. **Record the result** in the log section below.

7. **Tear down** the restore container.

---

## Restore Log

| Date       | Backup date restored | Row count match | Schema OK | Notes        |
|------------|----------------------|-----------------|-----------|--------------|
| _(pending)_ | —                   | —               | —         | First restore pending before launch |

---

## Alerting

- Hetzner sends email alerts when backup jobs fail.
- Monitor `nexstage-ops@` (or equivalent) mailbox weekly.
- `PurgeDeletedWorkspaceJob` runs after 30-day soft-delete window — ensure recent backup
  exists before hard-deletion of any workspace.

---

## Recovery Point Objective (RPO) and Recovery Time Objective (RTO)

| Metric | Target          | Notes                                      |
|--------|-----------------|--------------------------------------------|
| RPO    | 1 hour          | WAL archiving; worst case = 1 h of data    |
| RTO    | < 4 hours       | Manual restore; no automated failover yet  |

Automated failover (primary/replica promotion) is a Phase 4 infrastructure item.
