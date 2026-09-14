<?php

test('OAuth links never match by email and reject another initiator', function () use ($c, $pdo, $users) {
    $local = new \Naf\Auth\Auth();
    $local->addProvider('users', $c->get(\Naf\Auth\Provider\OrmProvider::class));
    $links = new \Naf\OAuth\Client\Account\PdoAccountLinks($pdo);
    $accounts = new \Naf\OAuth\Client\Account\Accounts($links, $local, 'users', false);
    $identity = new \Naf\OAuth\Client\Identity\ExternalIdentity('fixture', 'https://issuer.example.test', 'fixture-alice', email:'alice@example.test', emailVerified:true);
    $login = new \Naf\OAuth\Client\Core\Callback($identity, 'login', null, '/');
    try {
        $accounts->complete($login);
        throw new RuntimeException('Email linked automatically');
    } catch (\Naf\OAuth\Client\Exception\OAuthException $e) {
        check($e->reason === 'not_linked', 'wrong unlinked error');
    }
    $local->setIdentity($users['alice'], 'users');
    $link = new \Naf\OAuth\Client\Core\Callback($identity, 'link', ['provider' => 'users','id' => $users['alice']->getIdentifier()], '/');
    $local->setIdentity($users['bob'], 'users');
    try {
        $accounts->complete($link);
        throw new RuntimeException('Different initiator accepted');
    } catch (\Naf\OAuth\Client\Exception\OAuthException $e) {
        check($e->reason === 'initiator_mismatch', 'wrong initiator error');
    }
    $local->setIdentity($users['alice'], 'users');
    $accounts->complete($link);
    $local->logout();
    check($accounts->complete($login)->getIdentifier() === $users['alice']->getIdentifier(), 'explicit link failed');
});
