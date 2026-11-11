<?php

declare(strict_types=1);

/**
 * Preflight: the things that must be true before any Zabbix component is
 * installed. Timezone and time synchronisation are not a separate feature of
 * ZbxDeploy - they are a precondition, and this is where they live.
 */

return [
    'id'      => 'zabbix-preflight',
    'label'   => 'Preflight (time, NTP, capacity)',
    'summary' => 'Sets the timezone, makes sure the clock is synchronised, and checks that the host has room for a Zabbix component. Zabbix triggers and graphs are meaningless on a host whose clock drifts.',
    'type'    => 'state',
    'risk'    => 'low',
    'order'   => 10,

    'requires_facts' => ['os_family'],

    'params' => [
        'timezone' => [
            'type'     => 'text',
            'label'    => 'Timezone',
            'default'  => 'Europe/Belgrade',
            'required' => true,
            'pattern'  => '#^[A-Za-z]+/[A-Za-z0-9_+\-/]+$|^UTC$#',
            'help'     => 'An IANA timezone name, for example Europe/Belgrade or UTC.',
        ],
        'manage_time_sync' => [
            'type'    => 'bool',
            'label'   => 'Manage time synchronisation',
            'default' => true,
            'help'    => 'Install and enable systemd-timesyncd and write the NTP servers below. Turn this off if the host already uses chrony or ntpd.',
        ],
        'ntp_servers' => [
            'type'    => 'list',
            'label'   => 'NTP servers',
            'default' => ['0.rs.pool.ntp.org', '1.rs.pool.ntp.org', '2.rs.pool.ntp.org'],
            'help'    => 'One per line. Used only when time synchronisation is managed.',
        ],
        'min_free_mb' => [
            'type'    => 'number',
            'label'   => 'Minimum free space on / (MB)',
            'default' => 5120,
            'min'     => 256,
            'max'     => 1048576,
            'help'    => 'The job fails if the host has less. A Zabbix server with its database needs considerably more than an agent.',
        ],
    ],
];
