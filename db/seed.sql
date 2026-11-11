-- db/seed.sql
-- ZbxDeploy seed data
-- Version matrix. Adding a new Zabbix release or a new Ubuntu release is an
-- INSERT here plus a template directory under ansible/templates/ — no PHP change.
--
-- Always verify repo_deb_url against https://www.zabbix.com/download before
-- enabling a row: Zabbix has published repositories late (and briefly pulled
-- packages) for brand-new Ubuntu releases.

SET NAMES utf8mb4;

INSERT INTO settings (k, v) VALUES
    ('schema_version', '1'),
    ('app_name', 'ZbxDeploy')
ON DUPLICATE KEY UPDATE v = VALUES(v);

INSERT INTO zabbix_versions
    (zabbix_version, os_family, os_codename, os_version, repo_deb_url,
     sql_scripts_path, template_dir, is_lts, is_supported, is_default,
     eol_full_support, notes)
VALUES
    ('7.0', 'ubuntu', 'noble', '24.04',
     'https://repo.zabbix.com/zabbix/7.0/release/ubuntu/pool/main/z/zabbix-release/zabbix-release_latest_7.0+ubuntu24.04_all.deb',
     '/usr/share/zabbix-sql-scripts/mysql/server.sql.gz', '7.0', 1, 1, 1,
     '2027-06-30', 'Primary target. LTS, limited support until 2029-06-30.'),

    ('7.0', 'ubuntu', 'jammy', '22.04',
     'https://repo.zabbix.com/zabbix/7.0/release/ubuntu/pool/main/z/zabbix-release/zabbix-release_latest_7.0+ubuntu22.04_all.deb',
     '/usr/share/zabbix-sql-scripts/mysql/server.sql.gz', '7.0', 1, 1, 0,
     '2027-06-30', 'For existing 22.04 fleets.'),

    ('7.4', 'ubuntu', 'noble', '24.04',
     'https://repo.zabbix.com/zabbix/7.4/release/ubuntu/pool/main/z/zabbix-release/zabbix-release_latest_7.4+ubuntu24.04_all.deb',
     '/usr/share/zabbix-sql-scripts/mysql/server.sql.gz', '7.4', 0, 0, 0,
     NULL, 'Standard release, short lifecycle. Disabled until templates are verified.'),

    ('8.0', 'ubuntu', 'noble', '24.04',
     'https://repo.zabbix.com/zabbix/8.0/release/ubuntu/pool/main/z/zabbix-release/zabbix-release_latest_8.0+ubuntu24.04_all.deb',
     '/usr/share/zabbix-sql-scripts/mysql/server.sql.gz', '8.0', 1, 0, 0,
     NULL, 'Next LTS. Disabled until templates are verified.'),

    ('8.0', 'ubuntu', 'resolute', '26.04',
     'https://repo.zabbix.com/zabbix/8.0/release/ubuntu/pool/main/z/zabbix-release/zabbix-release_latest_8.0+ubuntu26.04_all.deb',
     '/usr/share/zabbix-sql-scripts/mysql/server.sql.gz', '8.0', 1, 0, 0,
     NULL, 'Disabled. Verify the repository actually publishes a Release file first.')
ON DUPLICATE KEY UPDATE repo_deb_url = VALUES(repo_deb_url);

INSERT INTO environments (name, description, zabbix_version, db_engine, status)
VALUES ('default', 'First environment - rename or replace as needed', '7.0', 'mysql', 'draft')
ON DUPLICATE KEY UPDATE description = VALUES(description);
