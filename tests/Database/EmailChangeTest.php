<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\AccountTestCase;
use Naf\Mail\Core\TransportInterface;
use Naf\Mail\Models\Mail;

/**
 * Changing the address an account signs in with.
 *
 * Nothing changes until the new address has proved it receives mail, because the
 * address is the way back into the account: a typo would lock the person out,
 * and a stranger's address would let them in. So the change is requested, a code
 * is sent, and only the code moves it -- once, from the account that asked, and
 * only while the address is still free.
 */
final class EmailChangeTest extends AccountTestCase
{
    public function testTheAddressIsNormalisedAndTheCodeIsNeverReturned(): void
    {
        [$user, $accounts, $transport] = $this->newAccount();
        $email                         = 'verified-' . $user->getId() . '@example.test';

        $pending = $accounts->requestEmail($this->emailRequest(strtoupper($email)));

        $this->assertSame($email, $pending['email'], 'the address was not normalised');
        $this->assertArrayNotHasKey('code', $pending, 'the response carried the code');
        $this->assertSame(
            $user->getProfile()->email,
            $this->scalar('SELECT email FROM users WHERE id=?', [$user->getId()]),
            'the address changed before it was proved',
        );
    }

    /** Only a hash is kept, so the stored row is not itself a way in. */
    public function testOnlyAHashOfTheCodeIsStored(): void
    {
        [$user, $accounts, $transport] = $this->newAccount();
        $pending                       = $accounts->requestEmail(
            $this->emailRequest('hashed-' . $user->getId() . '@example.test'),
        );
        $code = $this->codeFrom($transport);

        $row = $this->fetchOne('SELECT * FROM account_email_changes WHERE user_id=?', [$user->getId()]);

        $this->assertSame(
            hash('sha256', $pending['request_id'] . ':' . str_replace('-', '', $code)),
            $row['code_hash'],
        );
    }

    public function testTheCodeMovesTheAddressAndEndsEveryOtherSession(): void
    {
        [$user, $accounts, $transport] = $this->newAccount();
        $email                         = 'confirmed-' . $user->getId() . '@example.test';
        $pending                       = $accounts->requestEmail($this->emailRequest($email));

        // Typed back in as it is read out, which is not how it was sent.
        $accounts->confirmEmail([
            'request_id' => $pending['request_id'],
            'code'       => strtolower($this->codeFrom($transport)),
        ]);

        $this->auth->setIdentity($this->auth->load('users', $user->getIdentifier()));
        $profile = $this->auth->user()->getProfile();
        $this->assertSame($email, $profile->email);
        $this->assertTrue($profile->emailVerified, 'the new address was not marked as verified');
        $this->assertSame(1, $this->auth->user()->securityVersion(), 'the sessions were not revoked');
    }

    public function testACodeWorksOnceOnly(): void
    {
        [$user, $accounts, $transport] = $this->newAccount();
        $pending                       = $accounts->requestEmail(
            $this->emailRequest('once-' . $user->getId() . '@example.test'),
        );
        $code = $this->codeFrom($transport);
        $accounts->confirmEmail(['request_id' => $pending['request_id'], 'code' => $code]);
        // The change ended every session, this one included, so the caller signs
        // in again before asking anything else -- otherwise the replayed code
        // would be refused for the session rather than for being used up.
        $this->auth->setIdentity($this->auth->load('users', $user->getIdentifier()));

        $this->assertDenied(422, fn() => $accounts->confirmEmail([
            'request_id' => $pending['request_id'],
            'code'       => $code,
        ]));
    }

    public function testTheCurrentPasswordIsNeededToAskAtAll(): void
    {
        [, $accounts] = $this->newAccount();

        $this->assertDenied(403, fn() => $accounts->requestEmail([
            'email'            => 'unused@example.test',
            'current_password' => 'wrong',
        ]));
    }

    public function testAnAddressSomebodyElseUsesIsRefused(): void
    {
        [$other]      = $this->newAccount();
        [, $accounts] = $this->newAccount();

        // Upper case on purpose: the check has to normalise before comparing.
        $this->assertDenied(409, fn() => $accounts->requestEmail(
            $this->emailRequest(strtoupper($other->getProfile()->email)),
        ));
    }

    public function testTheAddressTheAccountAlreadyHasIsRefused(): void
    {
        [$user, $accounts] = $this->newAccount();

        $this->assertDenied(422, fn() => $accounts->requestEmail(
            $this->emailRequest($user->getProfile()->email),
        ));
    }

    /**
     * The address was free when it was asked for, and taken by the time the code
     * came back. Checking only at the start would leave two accounts sharing one
     * address, which is one account too many for signing in.
     */
    public function testAnAddressTakenInTheMeantimeIsRefusedAtConfirmation(): void
    {
        [$user, $accounts, $transport] = $this->newAccount();
        $email                         = 'collision-' . $user->getId() . '@example.test';
        $pending                       = $accounts->requestEmail($this->emailRequest($email));
        $code                          = $this->codeFrom($transport);

        [$other] = $this->newAccount();
        $this->pdo->prepare('UPDATE users SET email=? WHERE id=?')->execute([$email, $other->getId()]);
        $this->auth->setIdentity($user);

        $this->assertDenied(409, fn() => $accounts->confirmEmail([
            'request_id' => $pending['request_id'],
            'code'       => $code,
        ]));
        $this->assertSame($user->getProfile()->email, $accounts->profile()['email']);
    }

    public function testAnotherAccountCanNeitherUseNorCancelTheRequest(): void
    {
        [$owner, $accounts, $transport] = $this->newAccount();
        $pending                        = $accounts->requestEmail(
            $this->emailRequest('isolation-' . $owner->getId() . '@example.test'),
        );
        $code = $this->codeFrom($transport);

        [, $otherAccounts] = $this->newAccount();

        $this->assertDenied(422, fn() => $otherAccounts->confirmEmail([
            'request_id' => $pending['request_id'],
            'code'       => $code,
            'user_id'    => $owner->getId(),
        ]));
        $otherAccounts->cancelEmail();

        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM account_email_changes WHERE user_id=?', [$owner->getId()]),
            'another account cancelled the request',
        );
    }

    /** Guessing is not an option: five wrong codes and the request is gone. */
    public function testFiveWrongCodesInvalidateTheRequestForGood(): void
    {
        [$user, $accounts, $transport] = $this->newAccount();
        $pending                       = $accounts->requestEmail(
            $this->emailRequest('attempts-' . $user->getId() . '@example.test'),
        );
        $code = $this->codeFrom($transport);

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->assertDenied(422, fn() => $accounts->confirmEmail([
                'request_id' => $pending['request_id'],
                'code'       => 'AAAAAAAAAAAA',
            ]));
        }

        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM account_email_changes WHERE user_id=?', [$user->getId()]),
            'the attempt counter was rolled back with the refused attempt',
        );
        $this->assertDenied(422, fn() => $accounts->confirmEmail([
            'request_id' => $pending['request_id'],
            'code'       => $code,
        ]), 'the right code still worked afterwards');
    }

    public function testAnExpiredRequestIsRefusedAndForgotten(): void
    {
        [$user, $accounts, $transport] = $this->newAccount();
        $pending                       = $accounts->requestEmail(
            $this->emailRequest('expired-' . $user->getId() . '@example.test'),
        );
        $this->pdo
            ->prepare('UPDATE account_email_changes SET expires_at=? WHERE user_id=?')
            ->execute([time() - 1, $user->getId()]);

        $this->assertDenied(422, fn() => $accounts->confirmEmail([
            'request_id' => $pending['request_id'],
            'code'       => $this->codeFrom($transport),
        ]));
        $this->assertNull($accounts->profile()['pending'], 'an expired request is still shown as pending');
    }

    public function testAskingAgainReplacesTheCodeThatWasSentBefore(): void
    {
        [$user, $accounts, $transport] = $this->newAccount();
        $email                         = 'resend-' . $user->getId() . '@example.test';
        $first                         = $accounts->requestEmail($this->emailRequest($email));
        $firstCode                     = $this->codeFrom($transport);

        $second = $accounts->requestEmail($this->emailRequest($email));

        $this->assertDenied(422, fn() => $accounts->confirmEmail([
            'request_id' => $first['request_id'],
            'code'       => $firstCode,
        ]), 'the replaced code still worked');
        $this->assertSame($second['request_id'], $accounts->profile()['pending']['request_id']);
    }

    public function testCancellingLeavesTheAddressAsItWas(): void
    {
        [$user, $accounts] = $this->newAccount();
        $accounts->requestEmail($this->emailRequest('cancelled-' . $user->getId() . '@example.test'));

        $accounts->cancelEmail();

        $this->assertNull($accounts->profile()['pending']);
        $this->assertSame($user->getProfile()->email, $accounts->profile()['email']);
    }

    /**
     * The request and the mail have to succeed or fail together. A request left
     * behind by an undelivered mail is a pending change nobody can complete and
     * nobody asked to keep.
     */
    public function testAnUndeliveredMailLeavesNoRequestBehind(): void
    {
        $failing = new class implements TransportInterface {
            public function sendMail(Mail $mail): bool
            {
                return false;
            }
        };
        [$user, $accounts] = $this->newAccount($failing);

        $this->assertDenied(503, fn() => $accounts->requestEmail(
            $this->emailRequest('failed-' . $user->getId() . '@example.test'),
        ));

        $this->assertNull($accounts->profile()['pending'], 'the request survived the undelivered mail');
        $this->assertSame($user->getProfile()->email, $accounts->profile()['email']);
    }
}
