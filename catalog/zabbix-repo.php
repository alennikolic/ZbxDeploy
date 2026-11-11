<?php
// catalog/zabbix-repo.php

declare(strict_types=1);

/**
 * Registers the official Zabbix apt repository.
 *
 * The release package URL is never entered here: ZbxDeploy looks it up in the
 * zabbix_versions table using the environment's Zabbix version and the host's
 * own OS codename, and injects it at job time. That is what keeps the version
 * matrix in one place.
 */

return [
    'id'      => 'zabbix-repo',
    'label'   => 'Zabbix repository',
    'summary' => "Installs the official zabbix-release package for this environment's Zabbix version and the host's Ubuntu release, then verifies that apt really offers Zabbix packages afterwards.",
    'type'    => 'state',
    'risk'    => 'low',
    'order'   => 20,

    // Without a codename there is no way to pick the right release package.
    'requires_facts' => ['os_codename'],

    'params' => [
        'remove_distro_packages' => [
            'type'    => 'bool',
            'label'   => 'Remove Zabbix packages from the Ubuntu repository first',
            'default' => false,
            'help'    => "Ubuntu ships its own frozen Zabbix packages. Upgrading from them is unsupported, so they must go before the official repository is used. Leave this off unless the probe reported Zabbix packages already present.",
        ],
        'fix_broken_tools_repo' => [
            'type'    => 'bool',
            'label'   => 'Work around a broken zabbix-tools repository',
            'default' => true,
            'help'    => 'The release package also registers a zabbix-tools repository, which has been missing its Release file on some Ubuntu versions and breaks apt update. When that happens, disable that one file and retry instead of failing the job.',
        ],
    ],
];
