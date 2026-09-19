<?php

use Naf\Auth\Session\SessionStateStore;
use Naf\Auth\Support\PasswordHasher;
use Naf\Board\Jobs\AccountSecurityNoticeJob;
use Naf\Board\Models\User;
use Naf\Board\Services\AccountService;
use Naf\Board\Support\AccountStateStore;
use Naf\CLI\Core\Output;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Core\Transport\DummyTransport;
use Naf\Mail\Core\TransportInterface;
use Naf\Mail\Models\Mail;
use Naf\Queue\Core\Queue;
use Naf\RateLimit\PdoLimiter;
use Naf\Session\Core\Session;

$accountFixture = function (?TransportInterface $transport = null) use ($c, $pdo, $entityManager, $auth, $access): array {
    $hasher = $c->get(PasswordHasher::class);
    $user   = new User([
        'name'          => 'Account test',
        'email'         => 'account-' . bin2hex(random_bytes(8)) . '@example.test',
        'password_hash' => $hasher->hash('Original account password!'),
        'created_at'    => gmdate('Y-m-d H:i:s'),
    ]);
    $entityManager->save($user);
    $auth->setIdentity($user);
    $transport ??= new DummyTransport();
    $service = new AccountService($pdo, $auth, $access, $entityManager, $hasher, $c->get(PdoLimiter::class), new Mailer($transport), $c->get(Queue::class));

    return [$user, $service, $transport];
};
$emailRequest = fn(string $email): array => ['email' => $email, 'current_password' => 'Original account password!'];
$codeFrom     = function (DummyTransport $transport): string {
    $messages = $transport->getMessages();
    $message  = $messages[array_key_last($messages)];
    check(preg_match('/Bestätigungscode: ([A-Z2-9-]+)/u', $message->getContent(), $matches) === 1, 'verification mail missing code');

    return $matches[1];
};
$newPassword = [
    'current_password'      => 'Original account password!',
    'password'              => 'My changed account password!',
    'password_confirmation' => 'My changed account password!',
];

test('profile exposes only safe own-account fields', function () use ($accountFixture) {
    [$user, $accounts] = $accountFixture();
    $profile           = $accounts->profile();
    check($profile['email'] === $user->getProfile()->email && $profile['local_password'] === true, 'wrong account');
    check(!isset($profile['password_hash'], $profile['security_version']) && $profile['pending'] === null, 'private credentials exposed');
});
test('password change requires reauthentication and matching strong password', function () use ($accountFixture, $newPassword) {
    [$user, $accounts] = $accountFixture();
    $before            = scalar('SELECT password_hash FROM users WHERE id=?', [$user->getId()]);
    denied(403, fn() => $accounts->changePassword([...$newPassword, 'current_password' => 'wrong']));
    denied(422, fn() => $accounts->changePassword([...$newPassword, 'password_confirmation' => 'Different enough password!']));
    denied(422, fn() => $accounts->changePassword([...$newPassword, 'password' => 'short']));
    denied(422, fn() => $accounts->changePassword([...$newPassword, 'password' => str_repeat('ä', 37)]));
    check(scalar('SELECT password_hash FROM users WHERE id=?', [$user->getId()]) === $before, 'rejected change persisted');
});
test('password update hashes with NAF, revokes sessions and pending email, queues notice', function () use ($accountFixture, $newPassword, $emailRequest, $c) {
    [$user, $accounts] = $accountFixture();
    $accounts->requestEmail($emailRequest('pending-' . $user->getId() . '@example.test'));
    $accounts->changePassword($newPassword);
    $hash   = scalar('SELECT password_hash FROM users WHERE id=?', [$user->getId()]);
    $hasher = $c->get(PasswordHasher::class);
    check($hasher->verify($newPassword['password'], $hash) && !$hasher->verify($newPassword['current_password'], $hash), 'password hashing failed');
    check((int) scalar('SELECT security_version FROM users WHERE id=?', [$user->getId()]) === 1, 'sessions not revoked');
    check((int) scalar('SELECT COUNT(*) FROM account_email_changes WHERE user_id=?', [$user->getId()]) === 0, 'pending request survived password change');
    denied(401, fn() => $accounts->cancelEmail());
});
test('email request requires current password and available normalized address', function () use ($accountFixture, $emailRequest) {
    [$user, $accounts] = $accountFixture();
    denied(403, fn() => $accounts->requestEmail(['email' => 'unused@example.test', 'current_password' => 'wrong']));
    denied(409, fn() => $accounts->requestEmail($emailRequest('ALICE@example.test')));
    denied(422, fn() => $accounts->requestEmail($emailRequest($user->getProfile()->email)));
});
test('email stays unchanged until single-use code verification; only hash is stored', function () use ($accountFixture, $emailRequest, $codeFrom, $auth, $pdo) {
    [$user, $accounts, $transport] = $accountFixture();
    $email                         = 'verified-' . $user->getId() . '@example.test';
    $pending                       = $accounts->requestEmail($emailRequest(strtoupper($email)));
    $code                          = $codeFrom($transport);
    check($pending['email'] === $email && !isset($pending['code']), 'response exposes code or fails normalization');
    $row = $pdo->query('SELECT * FROM account_email_changes WHERE user_id=' . (int) $user->getId())->fetch();
    check($row['code_hash'] === hash('sha256', $pending['request_id'] . ':' . str_replace('-', '', $code)), 'wrong code hash');
    check(scalar('SELECT email FROM users WHERE id=?', [$user->getId()]) === $user->getProfile()->email, 'email changed before proof');
    $accounts->confirmEmail(['request_id' => $pending['request_id'], 'code' => strtolower($code)]);
    $auth->setIdentity($auth->load('users', $user->getIdentifier()));
    check($auth->user()->getProfile()->emailVerified && $auth->user()->getProfile()->email === $email, 'native UserProfile is not verified');
    check($auth->user()->securityVersion() === 1, 'email did not revoke sessions');
    denied(422, fn() => $accounts->confirmEmail(['request_id' => $pending['request_id'], 'code' => $code]));
});
test('another account cannot use or cancel a verification request', function () use ($accountFixture, $emailRequest, $codeFrom, $auth) {
    [$owner, $accounts, $transport] = $accountFixture();
    $pending                        = $accounts->requestEmail($emailRequest('isolation-' . $owner->getId() . '@example.test'));
    $code                           = $codeFrom($transport);
    [$other, $otherAccounts]        = $accountFixture();
    denied(422, fn() => $otherAccounts->confirmEmail(['request_id' => $pending['request_id'], 'code' => $code, 'user_id' => $owner->getId()]));
    $otherAccounts->cancelEmail();
    check((int) scalar('SELECT COUNT(*) FROM account_email_changes WHERE user_id=?', [$owner->getId()]) === 1, 'foreign account cancelled request');
    $auth->setIdentity($owner);
    $accounts->confirmEmail(['request_id' => $pending['request_id'], 'code' => $code]);
});
test('five incorrect codes permanently invalidate that request', function () use ($accountFixture, $emailRequest, $codeFrom) {
    [$user, $accounts, $transport] = $accountFixture();
    $pending                       = $accounts->requestEmail($emailRequest('attempts-' . $user->getId() . '@example.test'));
    $code                          = $codeFrom($transport);
    for ($attempt = 0; $attempt < 5; ++$attempt) {
        denied(422, fn() => $accounts->confirmEmail(['request_id' => $pending['request_id'], 'code' => 'AAAAAAAAAAAA']));
    }
    check((int) scalar('SELECT COUNT(*) FROM account_email_changes WHERE user_id=?', [$user->getId()]) === 0, 'attempt counter rolled back');
    denied(422, fn() => $accounts->confirmEmail(['request_id' => $pending['request_id'], 'code' => $code]));
});
test('expired verification fails and is removed', function () use ($accountFixture, $emailRequest, $codeFrom, $pdo) {
    [$user, $accounts, $transport] = $accountFixture();
    $pending                       = $accounts->requestEmail($emailRequest('expired-' . $user->getId() . '@example.test'));
    $pdo->prepare('UPDATE account_email_changes SET expires_at=? WHERE user_id=?')->execute([time() - 1, $user->getId()]);
    denied(422, fn() => $accounts->confirmEmail(['request_id' => $pending['request_id'], 'code' => $codeFrom($transport)]));
    check($accounts->profile()['pending'] === null, 'expired pending shown');
});
test('resend invalidates previous code and cancellation preserves current email', function () use ($accountFixture, $emailRequest, $codeFrom) {
    [$user, $accounts, $transport] = $accountFixture();
    $email                         = 'resend-' . $user->getId() . '@example.test';
    $old                           = $accounts->requestEmail($emailRequest($email));
    $code                          = $codeFrom($transport);
    $new                           = $accounts->requestEmail($emailRequest($email));
    denied(422, fn() => $accounts->confirmEmail(['request_id' => $old['request_id'], 'code' => $code]));
    check($accounts->profile()['pending']['request_id'] === $new['request_id'], 'old request invalidated new request');
    $accounts->cancelEmail();
    check($accounts->profile()['pending'] === null && $accounts->profile()['email'] === $user->getProfile()->email, 'cancel changed email');
});
test('email uniqueness is checked again at confirmation', function () use ($accountFixture, $emailRequest, $codeFrom, $auth, $pdo) {
    [$user, $accounts, $transport] = $accountFixture();
    $email                         = 'collision-' . $user->getId() . '@example.test';
    $pending                       = $accounts->requestEmail($emailRequest($email));
    $code                          = $codeFrom($transport);
    [$other]                       = $accountFixture();
    $pdo->prepare('UPDATE users SET email=? WHERE id=?')->execute([$email, $other->getId()]);
    $auth->setIdentity($user);
    denied(409, fn() => $accounts->confirmEmail(['request_id' => $pending['request_id'], 'code' => $code]));
    check($accounts->profile()['email'] === $user->getProfile()->email, 'collision changed account');
});
test('failed mail delivery rolls back request without changing account', function () use ($accountFixture, $emailRequest) {
    $transport = new class implements TransportInterface {
        public function sendMail(Mail $mail): bool
        {
            return false;
        }
    };
    [$user, $accounts] = $accountFixture($transport);
    denied(503, fn() => $accounts->requestEmail($emailRequest('failed-' . $user->getId() . '@example.test')));
    check($accounts->profile()['pending'] === null && $accounts->profile()['email'] === $user->getProfile()->email, 'failed delivery persisted request');
});
test('email send and password reauthentication use native account rate limits', function () use ($accountFixture, $emailRequest, $newPassword) {
    [$user, $accounts] = $accountFixture();
    for ($attempt = 0; $attempt < 3; ++$attempt) {
        $accounts->requestEmail($emailRequest('limited-' . $user->getId() . '@example.test'));
    }
    denied(429, fn() => $accounts->requestEmail($emailRequest('limited-again@example.test')));
    [$user, $accounts] = $accountFixture();
    for ($attempt = 0; $attempt < 10; ++$attempt) {
        denied(403, fn() => $accounts->changePassword([...$newPassword, 'current_password' => 'wrong']));
    }
    denied(429, fn() => $accounts->changePassword($newPassword));
});
test('external-only accounts cannot change credentials through local password flow', function () use ($accountFixture, $emailRequest, $newPassword, $pdo) {
    [$user, $accounts] = $accountFixture();
    $pdo->prepare('UPDATE users SET password_hash=NULL WHERE id=?')->execute([$user->getId()]);
    check(!$accounts->profile()['local_password'], 'external account exposes password controls');
    denied(403, fn() => $accounts->changePassword($newPassword));
    denied(403, fn() => $accounts->requestEmail($emailRequest('external-new@example.test')));
});
test('NAF session store preserves legacy revision zero and rejects revoked sessions', function () use ($accountFixture, $c, $pdo) {
    [$user]  = $accountFixture();
    $session = $c->get(Session::class);
    $native  = $c->get(SessionStateStore::class);
    $store   = new AccountStateStore($native, $session, $pdo);
    $native->write('users', $user->getIdentifier());
    $session->forget('account.security_version');
    check($store->read()['identifier'] === $user->getIdentifier(), 'legacy session lost during migration');
    $store->write('users', $user->getIdentifier());
    $pdo->prepare('UPDATE users SET security_version=security_version+1 WHERE id=?')->execute([$user->getId()]);
    check($store->read() === null && $native->read() === null, 'revoked session still accepted');
});
test('security writes recheck a persisted session after acquiring the account lock', function () use ($accountFixture, $auth, $pdo, $c, $newPassword) {
    [$user, $accounts] = $accountFixture();
    $pdo->prepare('UPDATE users SET security_version=1 WHERE id=?')->execute([$user->getId()]);
    $auth->setIdentity($auth->load('users', $user->getIdentifier()), 'users');
    // Model a revocation between session validation and the fresh identity lookup.
    $c->get(Session::class)->set('account.security_version', 0);
    denied(401, fn() => $accounts->changePassword($newPassword));
    check((int) scalar('SELECT security_version FROM users WHERE id=?', [$user->getId()]) === 1, 'stale restored session changed credentials');
});
test('native queue security notices reach old address without depending on notification preference', function () use ($c) {
    $transport = new DummyTransport();
    $job       = new AccountSecurityNoticeJob('old-address@example.test', 'email.changed', new Mailer($transport));
    $job->execute(new Output());
    $message = $transport->getMessages()[0];
    check($message->getRecipients() === ['old-address@example.test'] && !$message->isHtml(), 'security notice destination or format wrong');
});
$auth->setIdentity($users['alice']);
