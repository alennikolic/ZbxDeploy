-- db/schema.sql
-- ZbxDeploy schema
-- MySQL 8.x / MariaDB 10.11+
-- Applied automatically on first container start (docker-entrypoint-initdb.d).

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Key/value settings, schema version, encrypted globals
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    k           VARCHAR(64)  NOT NULL PRIMARY KEY,
    v           TEXT         NULL,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Supported Zabbix / OS combinations.
-- The whole point of this table: adding 7.4 or 8.0 later is a row, not a code
-- change. repo_deb_url is the zabbix-release package that registers the repo.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zabbix_versions (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    zabbix_version   VARCHAR(16)  NOT NULL,            -- 7.0, 7.4, 8.0
    os_family        VARCHAR(16)  NOT NULL DEFAULT 'ubuntu',
    os_codename      VARCHAR(32)  NOT NULL,            -- noble, jammy, resolute
    os_version       VARCHAR(16)  NOT NULL,            -- 24.04, 22.04, 26.04
    repo_deb_url     VARCHAR(512) NOT NULL,
    sql_scripts_path VARCHAR(255) NOT NULL DEFAULT '/usr/share/zabbix-sql-scripts/mysql/server.sql.gz',
    template_dir     VARCHAR(64)  NOT NULL,            -- ansible/templates/<dir>
    is_lts           TINYINT(1)   NOT NULL DEFAULT 0,
    is_supported     TINYINT(1)   NOT NULL DEFAULT 1,  -- offered in the UI
    is_default       TINYINT(1)   NOT NULL DEFAULT 0,
    eol_full_support DATE         NULL,
    notes            VARCHAR(255) NULL,
    UNIQUE KEY uq_version_os (zabbix_version, os_family, os_codename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- A Zabbix environment: one logical installation (server + db + frontend +
-- proxies + agents). Kept from day one even in single-environment installs.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS environments (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name               VARCHAR(64)  NOT NULL,
    description        VARCHAR(255) NULL,
    zabbix_version     VARCHAR(16)  NOT NULL DEFAULT '7.0',
    db_engine          ENUM('mysql','mariadb') NOT NULL DEFAULT 'mysql',
    zabbix_api_url     VARCHAR(255) NULL,
    zabbix_api_token   VARBINARY(1024) NULL,           -- sodium secretbox
    status             ENUM('draft','deploying','deployed','degraded') NOT NULL DEFAULT 'draft',
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_env_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- SSH credentials. Secrets are encrypted with APP_KEY (sodium secretbox) and
-- are never rendered back into the UI.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS credentials (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    environment_id  INT UNSIGNED NULL,                 -- NULL = shared
    name            VARCHAR(64)  NOT NULL,
    ssh_user        VARCHAR(64)  NOT NULL,
    auth_type       ENUM('key','password') NOT NULL DEFAULT 'key',
    secret          VARBINARY(8192) NOT NULL,          -- private key or password
    become_method   ENUM('sudo','none') NOT NULL DEFAULT 'sudo',
    become_password VARBINARY(1024) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cred_name (environment_id, name),
    CONSTRAINT fk_cred_env FOREIGN KEY (environment_id)
        REFERENCES environments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Inventory. A host belongs to one environment and can carry several roles.
-- facts holds whatever the probe job gathered; it is cache, not truth.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hosts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    environment_id  INT UNSIGNED NOT NULL,
    hostname        VARCHAR(128) NOT NULL,             -- name used in Zabbix
    address         VARCHAR(255) NOT NULL,             -- FQDN or IP for SSH
    ssh_port        SMALLINT UNSIGNED NOT NULL DEFAULT 22,
    credential_id   INT UNSIGNED NULL,
    os_family       VARCHAR(32)  NULL,
    os_version      VARCHAR(32)  NULL,
    os_codename     VARCHAR(32)  NULL,
    architecture    VARCHAR(16)  NULL,
    reachable       TINYINT(1)   NULL,                 -- NULL = never probed
    last_probe_at   TIMESTAMP    NULL,
    facts           JSON         NULL,
    notes           VARCHAR(512) NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_host_env_name (environment_id, hostname),
    KEY idx_host_env (environment_id),
    CONSTRAINT fk_host_env FOREIGN KEY (environment_id)
        REFERENCES environments (id) ON DELETE CASCADE,
    CONSTRAINT fk_host_cred FOREIGN KEY (credential_id)
        REFERENCES credentials (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Desired state: which Zabbix component should run on which host, with which
-- parameters. applied_hash lets us detect drift without re-running Ansible.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS host_roles (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    host_id       INT UNSIGNED NOT NULL,
    role          VARCHAR(32)  NOT NULL,               -- zabbix-server, zabbix-agent2, ...
    params        JSON         NULL,
    desired_state ENUM('present','absent') NOT NULL DEFAULT 'present',
    last_status   ENUM('pending','applied','failed') NOT NULL DEFAULT 'pending',
    applied_hash  CHAR(64)     NULL,
    applied_at    TIMESTAMP    NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_host_role (host_id, role),
    CONSTRAINT fk_hostrole_host FOREIGN KEY (host_id)
        REFERENCES hosts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Job queue. Also the job history: a worker claims rows with
-- SELECT ... FOR UPDATE SKIP LOCKED, so several workers can run safely.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jobs (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    environment_id INT UNSIGNED NULL,
    parent_job_id  INT UNSIGNED NULL,
    type           VARCHAR(48)  NOT NULL,              -- probe, apply, ...
    title          VARCHAR(160) NOT NULL,
    params         JSON         NULL,
    check_mode     TINYINT(1)   NOT NULL DEFAULT 0,    -- ansible --check
    status         ENUM('queued','running','success','failed','canceled') NOT NULL DEFAULT 'queued',
    exit_code      SMALLINT     NULL,
    worker_id      VARCHAR(64)  NULL,
    created_by     VARCHAR(64)  NOT NULL DEFAULT 'system',
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at     TIMESTAMP    NULL,
    finished_at    TIMESTAMP    NULL,
    KEY idx_jobs_claim (status, id),
    KEY idx_jobs_env (environment_id, id),
    CONSTRAINT fk_job_env FOREIGN KEY (environment_id)
        REFERENCES environments (id) ON DELETE SET NULL,
    CONSTRAINT fk_job_parent FOREIGN KEY (parent_job_id)
        REFERENCES jobs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Streamed output. The UI tails this by id > lastSeen.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_logs (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id     INT UNSIGNED NOT NULL,
    stream     ENUM('stdout','stderr','system') NOT NULL DEFAULT 'stdout',
    line       TEXT         NOT NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY idx_joblog_job (job_id, id),
    CONSTRAINT fk_joblog_job FOREIGN KEY (job_id)
        REFERENCES jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Who did what. Never contains secrets.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor       VARCHAR(64)  NOT NULL,
    action      VARCHAR(64)  NOT NULL,
    object_type VARCHAR(32)  NULL,
    object_id   INT UNSIGNED NULL,
    detail      JSON         NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
