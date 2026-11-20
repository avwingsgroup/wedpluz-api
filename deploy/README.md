# deploy/

Zero-downtime, automatically rolled-back deployments of the Wedpluz API to an Ubuntu VPS, safe on a server shared with other apps. The step-by-step guide is [docs/deployment-vps.md](../docs/deployment-vps.md).

| Path | Purpose |
|---|---|
| `scripts/provision.sh` | Setup as root: dedicated app user `deploy-wedpluz`, PHP 8.5 pool, PostgreSQL 19 database, nginx vhost, Supervisor programs, cron, backups, sudo rules, TLS |
| `scripts/deploy.sh` | Release script, run as `deploy-wedpluz` by GitHub Actions (or by hand as `wedpluz-deploy`) |
| `scripts/rollback.sh` | Switch back to an earlier release (`wedpluz-rollback`) |
| `scripts/backup.sh` | Nightly PostgreSQL dump with retention and optional S3 copy (`wedpluz-backup`) |
| `config/` | Templates: nginx vhost, PHP-FPM pool (holds all PHP settings), Supervisor `wedpluz-api-*` programs, cron, logrotate, sudoers |
| `env/production.env.example` | Production `.env` template; provisioning fills in the generated secrets |

Defaults: code in `/var/www/apps/wedpluz/api`, logs in `/var/log/apps/wedpluz`, backups in `/var/backups/apps/wedpluz`, operator login `usir`.

Quick reference, on the server as `usir`:

```bash
sudo -u deploy-wedpluz wedpluz-deploy --artifact /tmp/release.tar.gz   # what CI runs
sudo -u deploy-wedpluz wedpluz-rollback --list                          # releases, * = live
sudo -u deploy-wedpluz wedpluz-rollback                                 # previous release
sudo -u deploy-wedpluz wedpluz-backup                                   # database backup now
```
