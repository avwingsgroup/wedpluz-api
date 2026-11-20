# deploy/

Zero-downtime, automatically rolled-back deployments of the Wedpluz API to an Ubuntu VPS, safe on a server shared with other apps. The step-by-step guide is [docs/deployment-vps.md](../docs/deployment-vps.md).

| Path | Purpose |
|---|---|
| `scripts/provision.sh` | Setup as root: PHP 8.5 pool run by `usir`, PostgreSQL 19 database, nginx vhost, Supervisor programs, cron, backups, sudo rules, TLS |
| `scripts/deploy.sh` | Release script, run as `usir` by GitHub Actions (or by hand as `wedpluz-deploy`) |
| `scripts/rollback.sh` | Switch back to an earlier release (`wedpluz-rollback`) |
| `scripts/backup.sh` | Nightly PostgreSQL dump with retention and optional S3 copy (`wedpluz-backup`) |
| `config/` | Templates: nginx vhost, PHP-FPM pool (holds all PHP settings), Supervisor `wedpluz-api-*` programs, cron, logrotate, sudoers |
| `env/production.env.example` | Production `.env` template; provisioning fills in the generated secrets |

Defaults: code in `/var/www/apps/wedpluz/api`, logs in `/var/log/apps/wedpluz`, backups in `/var/backups/apps/wedpluz`, everything runs as `usir` (use `--app-user` for a separate app user).

Quick reference, on the server as `usir`:

```bash
wedpluz-deploy --artifact /tmp/release.tar.gz   # what CI runs
wedpluz-rollback --list                          # releases, * = live
wedpluz-rollback                                 # previous release
wedpluz-backup                                   # database backup now
```
