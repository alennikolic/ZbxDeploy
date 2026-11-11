# ZbxDeploy

**Deploy, configure and maintain Zabbix environments from one place.**

ZbxDeploy is a self-hosted web tool that installs and manages Zabbix components
on Linux servers: the repository, the database, the server, proxies, the frontend
and agents. You describe an environment once — which host plays which part — and
the tool works out the order, runs it, and keeps the configuration under its own
control so you can change it later without touching a config file by hand.

It is deliberately opinionated. It is not a generic automation runner that
happens to have a web UI; it knows what a Zabbix environment is made of, which
means it can derive most parameters instead of asking you for them twice.

> **Status: step 1 of the build.** What ships here is the foundation — container
> stack, schema, authentication, job queue with live output, inventory, and the
> probe job. Role deployment is the next step. See [Roadmap](#roadmap).

---

## What works today

- **Docker Compose stack**: nginx + PHP-FPM, a worker container with
  `ansible-core`, and MySQL.
- **Inventory**: environments, hosts and SSH credentials, with secrets encrypted
  at rest.
- **Job queue** in MySQL, drained by a worker that streams every line of Ansible
  output into the browser while the job runs.
- **Probe job**: reaches a host over SSH and reports OS release and codename,
  architecture, memory, free disk, timezone, clock sync, whether `sudo` works,
  whether Zabbix packages are already installed, and what the configured apt
  repositories would install. It changes nothing on the target.
- **Version matrix** as data: Zabbix release × Ubuntu release lives in a table,
  so supporting 7.4 or 8.0 later is a row and a template directory, not a code
  change.
- **Audit log** of every action, with no secrets in it.

Primary target: **Zabbix 7.0 LTS on Ubuntu Server 24.04**.

---

## Architecture

```
                 ┌─────────────────────────────────────────────┐
                 │               Docker Compose                │
                 │                                             │
   browser ────► │  web (nginx) ──► php (PHP-FPM)              │
                 │                      │                      │
                 │                      ▼                      │
                 │                  db (MySQL)  ◄── worker     │
                 │                                   │         │
                 └───────────────────────────────────┼─────────┘
                                                     │ SSH
                                                     ▼
                                        target Linux servers
```

The web tier never runs Ansible. It writes a row into `jobs` and returns
immediately; the worker claims the row (`SELECT … FOR UPDATE SKIP LOCKED`),
builds a throwaway inventory in a `0700` directory, runs `ansible-playbook`, and
writes each output line into `job_logs`. The job page tails that table. A ten
minute installation therefore never sits inside an HTTP request.

Key material is decrypted only inside the worker's temporary directory, which is
overwritten and removed in a `finally` block when the job ends.

### Layout

```
ansible/
  ansible.cfg            SSH and execution settings for every job
  playbooks/probe.yml    the probe job
  roles/                 Zabbix roles (next step)
  templates/7.0/         versioned config templates
bin/
  worker.php             queue worker entry point
  genkey.php             generate APP_KEY
  hash-password.php      generate ADMIN_PASSWORD_HASH
db/
  schema.sql             applied on first database start
  seed.sql               version matrix + a first environment
docker/
  nginx/default.conf
  php/Dockerfile         PHP 8.3 + ansible-core + openssh-client
public/index.php         front controller and route table
src/
  Core/                  config, database, crypto, auth, router, job queue
  Controller/            one controller per screen
  Worker/                Ansible runner and job handlers
templates/               Twig templates
```

---

## Quick start

Requirements: Docker with the Compose plugin. Nothing else — PHP, Composer and
Ansible all live in the image.

```bash
git clone https://github.com/<you>/zbxdeploy.git
cd zbxdeploy
cp .env.example .env
```

Build the image first, then generate the two secrets:

```bash
docker compose build

# Encryption key for stored credentials — back this up.
docker compose run --rm php php bin/genkey.php

# Operator password hash
docker compose run --rm php php bin/hash-password.php
```

Put both values into `.env` (`APP_KEY` and `ADMIN_PASSWORD_HASH`), set
`DB_PASSWORD` and `DB_ROOT_PASSWORD`, then:

```bash
docker compose up -d
docker compose logs -f worker
```

Open <http://localhost:8080> and sign in.

### First run

1. **Credentials** → add the SSH identity ZbxDeploy will use. Create a dedicated
   key for the tool; do not paste your personal one.
2. **Hosts** → add a server, attach the credential.
3. **Probe** → watch the output stream in. You now know whether the host is
   reachable, whether `sudo` works, and whether its release is one you have a
   Zabbix repository for.

Prepare each target host once:

```bash
sudo useradd -m -s /bin/bash zbxdeploy
sudo mkdir -p /home/zbxdeploy/.ssh
# paste the public half of the key you stored in ZbxDeploy
sudo nano /home/zbxdeploy/.ssh/authorized_keys
sudo chown -R zbxdeploy:zbxdeploy /home/zbxdeploy/.ssh
sudo chmod 700 /home/zbxdeploy/.ssh && sudo chmod 600 /home/zbxdeploy/.ssh/authorized_keys
echo 'zbxdeploy ALL=(ALL) NOPASSWD:ALL' | sudo tee /etc/sudoers.d/zbxdeploy
```

If you prefer not to grant `NOPASSWD`, leave the sudo password field filled in
when you create the credential instead.

---

## Adding a Zabbix or Ubuntu release

Everything version-specific is a row in `zabbix_versions`:

```sql
INSERT INTO zabbix_versions
    (zabbix_version, os_family, os_codename, os_version, repo_deb_url,
     sql_scripts_path, template_dir, is_lts, is_supported)
VALUES
    ('8.0', 'ubuntu', 'noble', '24.04',
     'https://repo.zabbix.com/zabbix/8.0/release/ubuntu/pool/main/z/zabbix-release/zabbix-release_latest_8.0+ubuntu24.04_all.deb',
     '/usr/share/zabbix-sql-scripts/mysql/server.sql.gz', '8.0', 1, 1);
```

Then copy `ansible/templates/7.0/` to `ansible/templates/8.0/` and diff the
configuration files against the new release.

**Always verify `repo_deb_url` against <https://www.zabbix.com/download> before
enabling a row.** Zabbix has published repositories late for brand-new Ubuntu
releases, and has briefly pulled packages after publishing them. The probe job
reports what apt actually offers on the target, which is the reliable check.

Zabbix 7.0 LTS has full support until 30 June 2027 and limited support until
30 June 2029. Plan the move to 8.0 before the first of those dates.

---

## Security

This tool holds SSH access to every server in your inventory. Treat it as
infrastructure, not as an internal website.

- **Never expose it to the internet.** Put it behind a VPN, or a reverse proxy
  with mTLS. Set `SESSION_SECURE_COOKIE=true` when serving over HTTPS.
- **`APP_KEY` is the crown jewel.** It decrypts every stored SSH key and
  password. Back it up separately from the database, and rotate credentials if
  it ever leaks.
- **Host keys are checked.** `StrictHostKeyChecking=accept-new` records a key on
  first contact and refuses the connection if it later changes. The `known_hosts`
  file lives in the `app_data` volume.
- **Secrets never reach the job log or the audit table.** When you add tasks that
  handle credentials, mark them `no_log: true` — Ansible will otherwise print
  them.
- **Credentials are write-only in the UI.** You can replace one; you cannot read
  it back.
- Use a dedicated unprivileged SSH user with `sudo`, not `root` logins.

---

## Roadmap

| Step | Scope |
|---|---|
| 1 ✅ | Container stack, schema, auth, job queue with live log, inventory, probe |
| 2 | Role catalogue and the first roles: `zabbix-repo`, `zabbix-preflight` (timezone + NTP), `zabbix-agent2` |
| 3 | `zabbix-db`, `zabbix-server`, `zabbix-frontend`; dependency-ordered deployment plan with check mode |
| 4 | `zabbix-proxy`, PSK key generation and distribution, Zabbix API registration of hosts and proxies |
| 5 | Config ownership: every `.conf` rendered from the database, drift detection, edit-and-reapply |
| 6 | Firewall rules derived from the topology, with a commit-confirmed rollback timer |
| 7 | Version upgrades as an ordinary job type |

Deliberately out of scope for now: Linux user account management, generic
automation, and anything that is not in service of running Zabbix.

---

## License

MIT. See `LICENSE`.
