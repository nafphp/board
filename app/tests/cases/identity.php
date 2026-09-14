<?php

use Naf\Auth\Auth;
use Naf\Auth\Provider\OrmProvider;
use Naf\OAuth\Client\Account\Accounts;
use Naf\OAuth\Client\Account\PdoAccountLinks;
use Naf\OAuth\Client\Core\Callback;
use Naf\OAuth\Client\Exception\OAuthException;
use Naf\OAuth\Client\Identity\ExternalIdentity;

test('OAuth links never match by email and reject another initiator', function () use (
    $c,
    $pdo,
    $users,
) {
    $local = new Auth();
    $local->addProvider('users', $c->get(OrmProvider::class));
    $links    = new PdoAccountLinks($pdo);
    $accounts = new Accounts($links, $local, 'users', false);
    $identity = new ExternalIdentity(
        'fixture',
        'https://issuer.example.test',
        'fixture-alice',
        email: 'alice@example.test',
        emailVerified: true,
    );
    $login = new Callback($identity, 'login', null, '/');

    try {
        $accounts->complete($login);
        throw new RuntimeException('Email linked automatically');
    } catch (OAuthException $exception) {
        check($exception->reason === 'not_linked', 'wrong unlinked error');
    }
    $local->setIdentity($users['alice'], 'users');
    $link = new Callback(
        $identity,
        'link',
        ['provider' => 'users', 'id' => $users['alice']->getIdentifier()],
        '/',
    );
    $local->setIdentity($users['bob'], 'users');

    try {
        $accounts->complete($link);
        throw new RuntimeException('Different initiator accepted');
    } catch (OAuthException $exception) {
        check($exception->reason === 'initiator_mismatch', 'wrong initiator error');
    }
    $local->setIdentity($users['alice'], 'users');
    $accounts->complete($link);
    $local->logout();
    check(
        $accounts->complete($login)->getIdentifier() === $users['alice']->getIdentifier(),
        'explicit link failed',
    );
});
