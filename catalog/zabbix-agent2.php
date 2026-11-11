<?php
// catalog/zabbix-agent2.php

declare(strict_types=1);

/**
 * Zabbix agent 2.
 *
 * The first role that owns a configuration file. /etc/zabbix/zabbix_agent2.conf
 * is rendered from a template chosen by the environment's Zabbix version, which
 * means changing a setting later is the same operation as installing: edit the
 * parameters, apply again.
 */

return [
    'id'      => 'zabbix-agent2',
    'label'   => 'Zabbix agent 2',
    'summary' => 'Installs zabbix-agent2 and renders its configuration from the template for this environment\'s Zabbix version. The agent is only installed and configured here - adding the host inside Zabbix itself is a separate step.',
    'type'    => 'state',
    'risk'    => 'low',
    'order'   => 60,

    'requires_facts'   => ['os_codename'],
    'requires_release' => true,

    'params' => [
        'server' => [
            'type'     => 'text',
            'label'    => 'Server or proxy address',
            'required' => true,
            'help'     => 'Goes into Server= - the addresses allowed to query this agent. A Zabbix proxy belongs here, not the server behind it. Comma-separated for several.',
        ],
        'server_active' => [
            'type'  => 'text',
            'label' => 'Address for active checks',
            'help'  => 'Goes into ServerActive=. Leave empty to disable active checks, which is the right choice when the server polls this host directly.',
        ],
        'agent_hostname' => [
            'type'  => 'text',
            'label' => 'Agent hostname',
            'help'  => 'Goes into Hostname= and must match the host name in Zabbix exactly. Leave empty to use the name this host carries in ZbxDeploy.',
        ],
        'listen_port' => [
            'type'    => 'number',
            'label'   => 'Listen port',
            'default' => 10050,
            'min'     => 1,
            'max'     => 65535,
        ],
        'timeout' => [
            'type'    => 'number',
            'label'   => 'Timeout (seconds)',
            'default' => 3,
            'min'     => 1,
            'max'     => 30,
            'help'    => 'How long a single item may take. Raise it only for checks you know are slow; a high value here delays every poller.',
        ],
        'allow_system_run' => [
            'type'    => 'bool',
            'label'   => 'Allow system.run[*] (remote command execution)',
            'default' => false,
            'help'    => 'Lets anyone who can reach this agent from an allowed address run arbitrary commands on it. Turn this on only for a host that genuinely needs it, and only when the Server= list is tight.',
        ],
    ],
];
