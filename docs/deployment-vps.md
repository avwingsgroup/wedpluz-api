# Deploying the API to a VPS

This guide installs the Wedpluz API on an Ubuntu server **that may already host other apps**, and connects it to GitHub Actions. You do steps 1–6 once. After that, every push to the `production` branch is tested, built and deployed with no downtime, and a failed release rolls itself back.

Everything Wedpluz uses is dedicated and namespaced, so other apps on the server are not touched:

| Resource | Wedpluz uses |
|---|---|
| App user (owns the code, runs PHP, workers and cron) | `deploy-wedpluz`: system user, no login shell, no SSH |
| Operator and CI login | `usir` (home `/home/usir`), which deploys through `sudo -u deploy-wedpluz` |
| Code | `/var/www/apps/wedpluz/api` |
| PHP | PHP 8.5, dedicated pool `wedpluz` in `php8.5-fpm`, socket `/run/php/php8.5-fpm-wedpluz.sock`. Settings live in the pool; global `php.ini` files are not changed. |
| Database | PostgreSQL 19: dedicated role and database `wedpluz`, other roles can't connect |
| Redis | the existing instance as-is (Wedpluz keys are prefixed `wedpluz-`); installed only if missing |
| nginx | dedicated virtual host `/etc/nginx/sites-available/wedpluz-api` |
| Supervisor | `/etc/supervisor/conf.d/wedpluz-api.conf`: group `wedpluz-api`, programs `wedpluz-api-queue`, `wedpluz-api-queue-long`, `wedpluz-api-reverb` |
| Cron, log rotation | `/etc/cron.d/wedpluz-api`, `/etc/logrotate.d/wedpluz-api` |
| Logs | `/var/log/apps/wedpluz` and the app's own `shared/storage/logs` |
| Backups | `/var/backups/apps/wedpluz` |
| sudo rules | `/etc/sudoers.d/wedpluz-api`, covering only `usir` → `deploy-wedpluz`, and `deploy-wedpluz` → its own FPM reload and Supervisor programs |
| Reverb (optional) | local port 8086, proxied by the vhost at `/app` |

| Piece | File | Runs |
|---|---|---|
| Server setup | `deploy/scripts/provision.sh` | once, as root |
| Release | `deploy/scripts/deploy.sh` (installed as `wedpluz-deploy`) | every deploy, as `deploy-wedpluz` |
| Rollback | `deploy/scripts/rollback.sh` (`wedpluz-rollback`) | when needed, as `deploy-wedpluz` |
| Database backup | `deploy/scripts/backup.sh` (`wedpluz-backup`) | nightly from cron |
| Tests | `.github/workflows/ci.yml` (PHP 8.5, PostgreSQL 19, Redis 7) | every push and pull request |
| Automatic deploy | `.github/workflows/deploy.yml` | push to `production`, or run by hand |
| Server config | `deploy/config/*` | rendered by `provision.sh` |
| Production env template | `deploy/env/production.env.example` | becomes `shared/.env` |

## How a deployment works

```text
push to production ──► GitHub Actions
                         1. tests     PHP 8.5 + PostgreSQL 19 + Redis 7, Pint
                         2. build     composer install --no-dev → release.tar.gz
                         3. deploy    ssh as usir → scp to /tmp → sudo -u deploy-wedpluz deploy.sh
                                         unpack into releases/<time>-<sha>
                                         link shared/.env and shared/storage
                                         php8.5 artisan migrate --force --isolated
                                         config / route / view / event caches (config cache mode 600)
                                         atomic switch of `current`  ◄── site changes here, no downtime
                                         reload php8.5-fpm, restart wedpluz-api workers
                                         health check /api/v1/health ── fails? ──► switch back automatically
                                         keep the last 5 releases
                         4. public health check through DNS
```

Server layout:

```text
/var/www/apps/wedpluz/api/
├── current -> releases/20261120101500-3f2a9c1   nginx serves current/public
├── releases/                                     last 5 releases (code readable by nginx)
├── shared/.env                                   secrets: mode 600, deploy-wedpluz only
├── shared/storage/                               logs, cache, private files: owner-only
│   └── app/public/                               public uploads: readable by nginx
└── deploy.log                                    every deploy and rollback
```

On a shared server, other apps' PHP pools often run as `www-data`. So the `.env`, the config cache (which holds the same secrets), logs and private files are readable only by `deploy-wedpluz`. The pool also runs with `open_basedir` limited to the app directory and `/tmp`.

## Before you start

- **The server:** Ubuntu 24.04 LTS (22.04 also works) with room for Wedpluz. Plan for at least 2 GB of RAM for the API on top of what other apps use. The PHP pool claims at most about half the RAM.
- **Existing services:** PHP 8.5 and PostgreSQL 19, if already installed, are reused. Otherwise PHP comes from the `ondrej/php` PPA and PostgreSQL from the official PostgreSQL apt repository.
- **Access:** root (or sudo) as `usir`.
- **DNS:** a name for the API, e.g. `api.wedpluz.com`, with an **A record** (and AAAA if IPv6) pointing at the server. No other nginx site may already use it; provisioning checks.
- **GitHub:** admin access to `avwingsgroup/wedpluz-api` to add secrets.
- **Credentials:** the provider keys for `.env` (ZeptoMail, Cloudinary, Paystack and so on).

## Step 1 — Create a deploy key

On your machine, create the key GitHub Actions will use to log in as `usir`:

```bash
ssh-keygen -t ed25519 -C "wedpluz-deploy" -f ~/.ssh/wedpluz_deploy -N ""
```

The public half (`~/.ssh/wedpluz_deploy.pub`) goes to the server; the private half goes into GitHub (step 4). Never commit either.

## Step 2 — Provision

Copy the `deploy/` folder and the public key into `usir`'s home, then run the script with sudo:

```bash
scp -r deploy ~/.ssh/wedpluz_deploy.pub usir@<server-ip>:/home/usir/
ssh usir@<server-ip>

sudo bash /home/usir/deploy/scripts/provision.sh \
  --domain api.wedpluz.com \
  --ssh-key-file /home/usir/wedpluz_deploy.pub \
  --email ops@wedpluz.com --ssl
```

Leave out `--ssl` if DNS doesn't point at the server yet (add HTTPS in step 3). Add `--reverb` for realtime WebSockets. Use `--no-postgres` or `--no-redis` with managed services. Add `--harden` **only on a fresh server** (firewall deny-by-default, fail2ban, security updates, UTC, swap). `--help` lists every option, including `--ssh-user`, `--app-user` and `--app-dir` if you need different names.

What it does:

1. **PHP 8.5:** installs the FPM and CLI packages with the needed extensions if missing. The server-wide `php` default is left alone; Wedpluz always calls `/usr/bin/php8.5`.
2. **PostgreSQL:** reuses the running server, or installs PostgreSQL 19. Creates the `wedpluz` role and database, and revokes `CONNECT` from everyone else.
3. **Redis:** reuses it without changing its configuration. If it isn't installed, installs it with a password and persistence.
4. **Users:** creates `deploy-wedpluz` (no login) and adds the deploy key to `/home/usir/.ssh/authorized_keys`.
5. **App directory:** creates `/var/www/apps/wedpluz/api` and a `shared/.env` with a new `APP_KEY`, database password, health-check token and Reverb keys. Secrets are also kept in `/root/wedpluz-credentials.txt`.
6. **Dedicated config:** writes the FPM pool, nginx vhost, Supervisor programs, cron, log rotation, sudo rules and the `wedpluz-deploy`/`wedpluz-rollback`/`wedpluz-backup` commands. nginx and PHP-FPM are tested before they reload; a failed test changes nothing.
7. **HTTPS:** with `--ssl`, issues a Let's Encrypt certificate named `wedpluz-api`, with automatic renewal.

It is safe to run again, for example to add `--reverb`: finished steps are skipped, and existing secrets and Certbot changes are kept.

## Step 3 — Fill in the production environment

```bash
sudo -u deploy-wedpluz nano /var/www/apps/wedpluz/api/shared/.env
```

Set at least:

- `WEDPLUZ_WEB_ORIGIN`, `WEDPLUZ_ADMIN_ORIGIN` and `CORS_ALLOWED_ORIGINS` for the real frontend domains.
- `ZEPTOMAIL_TOKEN`, then `CLOUDINARY_*`, then the payment provider keys you use, then `GOOGLE_*`.
- `REDIS_PASSWORD`, if the existing Redis requires one (provisioning warns you).
- `BACKUP_S3_URI` for an off-server copy of the nightly backup (recommended).

Leave the generated values as they are. [environment-variables.md](environment-variables.md) explains every variable.

If you skipped `--ssl`, enable HTTPS once DNS resolves to the server:

```bash
sudo certbot --nginx -d api.wedpluz.com --cert-name wedpluz-api --redirect -m ops@wedpluz.com --agree-tos
```

## Step 4 — Connect GitHub Actions

In GitHub: **Settings → Environments → New environment → `production`**. Optionally add *Required reviewers* to approve each deploy. Then add:

| Type | Name | Value |
|---|---|---|
| Secret | `VPS_HOST` | Server IP or host name |
| Secret | `VPS_USER` | `usir` |
| Secret | `VPS_PORT` | SSH port (omit for 22) |
| Secret | `VPS_SSH_KEY` | Contents of `~/.ssh/wedpluz_deploy` (the **private** key) |
| Secret | `VPS_KNOWN_HOSTS` | Output of `ssh-keyscan -t ed25519 <server-ip>` (compare its fingerprint with the server's) |
| Variable | `VPS_APP_USER` | `deploy-wedpluz` (default if unset) |
| Variable | `APP_URL` | `https://api.wedpluz.com` |

The workflow logs in as `usir` and runs only `sudo -n -u deploy-wedpluz /usr/bin/bash /tmp/wedpluz-deploy-<sha>.sh …`, which the dedicated sudo rule allows without a password. `VPS_KNOWN_HOSTS` pins the server's identity. Never replace it with `StrictHostKeyChecking=no`.

## Step 5 — First deployment

```bash
git checkout main && git pull
git checkout -b production && git push -u origin production
```

For the very first deploy, run it by hand once so reference data is seeded. Go to **Actions → Deploy → Run workflow** with ref `production`, and tick **Run ReferenceDataSeeder**. This seeds categories, plans, templates, admin roles and the onboarding slides.

Then create the first admin. It prints the password and TOTP secret once; store both in your password manager:

```bash
sudo -u deploy-wedpluz env ADMIN_BOOTSTRAP_EMAIL=you@wedpluz.com \
  /usr/bin/php8.5 /var/www/apps/wedpluz/api/current/artisan db:seed --force
```

**Never** run `php artisan wedpluz:demo` on a server. It refuses in production anyway.

## Step 6 — Verify

```bash
curl https://api.wedpluz.com/api/v1/health
# {"status":"ok","checks":{"database":"ok","redis":"ok","cache":"ok","queue":"ok","scheduler":"ok"}}

sudo -u deploy-wedpluz /usr/bin/php8.5 /var/www/apps/wedpluz/api/current/artisan wedpluz:health --ping-queue
sudo supervisorctl status 'wedpluz-api:*'
sudo tail -n 20 /var/www/apps/wedpluz/api/deploy.log
```

`queue` and `scheduler` can show `stale` for a minute after the first deploy, until workers and cron heartbeat. Then work through the provider checklist in [deployment.md](deployment.md#b4-provider-configuration-checklist).

## Day-to-day

**Deploy:** merge into `production` and push, then watch **Actions → Deploy**.

```bash
git checkout production && git merge --ff-only main && git push
```

**Roll back:** a failed health check rolls back on its own. To go back by hand:

```bash
sudo -u deploy-wedpluz wedpluz-rollback --list     # * marks the live release
sudo -u deploy-wedpluz wedpluz-rollback            # previous release
sudo -u deploy-wedpluz wedpluz-rollback --to 20261120101500-3f2a9c1
```

Rollbacks switch code only. Migrations stay applied, so keep them backward compatible for one release: add columns first, remove them in a later release.

**Deploy from the server** (if GitHub is unavailable). This needs a read-only deploy key for the repository, readable by `deploy-wedpluz`:

```bash
sudo -u deploy-wedpluz wedpluz-deploy --git production --repo git@github.com:avwingsgroup/wedpluz-api.git
```

**Change an environment value:**

```bash
sudo -u deploy-wedpluz nano /var/www/apps/wedpluz/api/shared/.env
sudo -u deploy-wedpluz /usr/bin/php8.5 /var/www/apps/wedpluz/api/current/artisan config:cache
sudo -u deploy-wedpluz /usr/bin/php8.5 /var/www/apps/wedpluz/api/current/artisan queue:restart
```

**Run artisan:** `sudo -u deploy-wedpluz /usr/bin/php8.5 /var/www/apps/wedpluz/api/current/artisan <command>`. `usir` may run this without a password.

**Backups:** `wedpluz-backup` runs nightly at 01:30 UTC into `/var/backups/apps/wedpluz` (14 days), and to `BACKUP_S3_URI` when set. Take one now with `sudo -u deploy-wedpluz wedpluz-backup`. To restore:

```bash
pg_restore --clean --if-exists --no-owner -h 127.0.0.1 -U wedpluz -d wedpluz /var/backups/apps/wedpluz/wedpluz-<date>.dump
```

Test a restore on a spare database every quarter.

**Logs:**

| What | Where |
|---|---|
| Application | `/var/www/apps/wedpluz/api/shared/storage/logs/laravel-YYYY-MM-DD.log` |
| Queue workers, Reverb, backups | `/var/log/apps/wedpluz/queue.log`, `queue-long.log`, `reverb.log`, `backup.log` |
| PHP-FPM pool | `/var/log/apps/wedpluz/php-fpm-error.log`, `php-fpm-slow.log` |
| Deploys | `/var/www/apps/wedpluz/api/deploy.log` |
| nginx | `/var/log/nginx/wedpluz-api.access.log`, `.error.log` |

`usir` is in the `deploy-wedpluz` group and can read `/var/log/apps/wedpluz` directly. The application logs need `sudo`.

## Troubleshooting

| Symptom | Check |
|---|---|
| Deploy fails at "Configure SSH" or "Upload release" | `VPS_KNOWN_HOSTS` matches `ssh-keyscan`; the public key is in `/home/usir/.ssh/authorized_keys`; the SSH port is open. |
| `sudo: a password is required` in "Activate release" | `sudo visudo -cf /etc/sudoers.d/wedpluz-api`; `sudo -l -U usir` must list the `/usr/bin/bash /tmp/wedpluz-deploy-*.sh` rule. |
| Provisioning stops at "Another nginx site already serves" | Remove or rename that site's `server_name`, then re-run. |
| `Missing .../shared/.env` | Run step 2 first. |
| Migration error | The release is discarded before the switch, so the live site is unchanged. Fix the migration and push again. |
| Rolled back after "Health check failed" | `curl -sk https://127.0.0.1/api/v1/health -H 'Host: api.wedpluz.com'` on the server shows which check is `down`; read the application log. |
| `queue: stale` in health | `sudo supervisorctl status 'wedpluz-api:*'`; `tail /var/log/apps/wedpluz/queue.log`. |
| `scheduler: stale` | `cat /etc/cron.d/wedpluz-api`; `journalctl -u cron -n 20`. |
| 502 Bad Gateway | `systemctl status php8.5-fpm`; `ls -l /run/php/php8.5-fpm-wedpluz.sock`; `tail /var/log/nginx/wedpluz-api.error.log`. |
| `open_basedir restriction in effect` in the log | Something reads outside the app directory or `/tmp`. Add the path to `php_admin_value[open_basedir]` in `/etc/php/8.5/fpm/pool.d/wedpluz.conf`, then `sudo systemctl reload php8.5-fpm`. |
| Redis `NOAUTH` errors | The existing Redis has a password: set `REDIS_PASSWORD` in `shared/.env`, then `config:cache`. |

## Scaling later

- **Database:** move to managed PostgreSQL. Re-run provisioning with `--no-postgres`, set `DB_*` and `DB_SSLMODE=require`, and restore a backup there.
- **Files:** set `PRIVATE_DISK_DRIVER=s3` so private files stop depending on this disk.
- **More traffic:** give Wedpluz its own server (re-provision there; the pool resizes from RAM), then several web servers behind a load balancer with managed PostgreSQL and Redis. Run cron and the long-job worker on one server only.
