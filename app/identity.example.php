<?php

// Copy to identity.local.php only after supplying your own trusted endpoints and secrets.
return [
    'auth' => [
        'logins' => [
            // 'company'=>['driver'=>'oidc','label'=>'Firmenkonto','issuer'=>'https://issuer.example.test','client_id'=>getenv('OIDC_CLIENT_ID'),'client_secret'=>getenv('OIDC_CLIENT_SECRET')],
        ],
    ],
    'oauth' => ['accounts' => ['provider' => 'users', 'auto_register' => false]],
    'ldap'  => [
        'enabled'    => false,
        'directory'  => 'company',
        'connection' => [
            'url'               => 'ldaps://directory.example.test:636',
            'baseDn'            => 'ou=people,dc=example,dc=test',
            'bindDn'            => 'uid=search,ou=services,dc=example,dc=test',
            'bindPassword'      => getenv('LDAP_BIND_PASSWORD'),
            'caFile'            => '/workspace/app/config/directory-ca.pem',
            'usernameAttribute' => 'mail',
            'subjectAttribute'  => 'entryUUID',
            'allowedFilter'     => '(objectClass=inetOrgPerson)',
            'timeout'           => 5,
        ],
    ],
];
