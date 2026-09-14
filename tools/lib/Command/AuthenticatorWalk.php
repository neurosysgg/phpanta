<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Origin;
use Phpanta\Http\PasskeyFormField;
use Phpanta\Model\Passkey\EntranceCeremony;
use Phpanta\Support\AdminPath;
use Phpanta\Support\Collection;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Http\CookieJar;
use Phpanta\Tool\Http\FormField;
use Phpanta\Tool\Http\OutboundHeader;
use Phpanta\Tool\Http\Request;
use Phpanta\Tool\Http\Response;
use Phpanta\Tool\Http\Transport;
use Phpanta\Tool\Http\TransportException;
use Phpanta\Tool\Http\Url;
use Phpanta\Tool\Passkey\AdminPage;
use Phpanta\Tool\Passkey\SoftwareDevice;

/**
 * The AuthenticatorWalk class. One browser's walk through the admin, for {@link Authenticator}: the
 * requests it sends, the cookies it keeps, and a line for every check, `ok` or `FAIL`.
 *
 * Each step takes the cookie jar it is to send and answers the one it leaves, rather than keeping
 * one of its own, so a step can send a jar from before an answer — a copied session — as easily as
 * the current one.
 */
final class AuthenticatorWalk
{
    /** A credential id nobody holds — `nobody`, base64url — which the walk's write revokes. */
    private const string NOBODY = 'bm9ib2R5';

    /** How many checks failed so far. */
    private int $failed = 0;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Transport $transport
     * @param Url       $url       The local copy's origin, as a URL requests are built on.
     * @param Origin    $origin    The same origin, as a browser states it and a ceremony binds it.
     * @param Output    $output
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly Url       $url,
        private readonly Origin    $origin,
        private readonly Output    $output,
    ) {}

    /**
     * Registers $device at the entrance, answering with the enrolment code the page hands back.
     *
     * @param SoftwareDevice $device
     * @return string|null
     * @throws TransportException
     */
    public function register(SoftwareDevice $device): ?string
    {
        [$entrance, $jar] = $this->entrance(CookieJar::empty());

        if ($entrance === null) {
            return null;
        }

        $answer = $this->post(AdminPath::Index->to(), $jar, SoftwareDevice::posting(
            (string) $entrance->token(),
            $device->registration((string) $entrance->challenge(), $this->origin),
            self::ceremony(EntranceCeremony::Register),
        ));
        $page   = AdminPage::of($answer->body);
        $code   = $page->enrolmentCode();

        $claim  = 'the entrance registers the device and hands back an enrolment code';

        if (!$this->check($code !== null, $claim, $answer)) {
            return null;
        }

        return $this->check(
            str_contains($page->said(), $device->fingerprint()),
            'the page shows this key\'s fingerprint, ' . $device->fingerprint(),
            $answer,
        ) ? $code : null;
    }

    /**
     * Walks the four promises with $device enrolled, answering whether every check passed.
     *
     * @param SoftwareDevice $device
     * @param string         $listing A read below the entrance.
     * @param string         $write   A write whose `passkey` field names a device nobody holds.
     * @return bool
     * @throws TransportException
     */
    public function promises(SoftwareDevice $device, string $listing, string $write): bool
    {
        [$entrance, $before] = $this->entrance(CookieJar::empty());

        if ($entrance === null) {
            return false;
        }

        // 1. An unlock opens the admin.
        $unlock   = SoftwareDevice::posting(
            (string) $entrance->token(),
            $device->assertion((string) $entrance->challenge(), $this->origin),
            self::ceremony(EntranceCeremony::Unlock),
        );
        $answered = $this->post(AdminPath::Index->to(), $before, $unlock);
        $jar      = $before->keeping($answered);
        $read     = $this->get($listing, $jar);

        $opened = $answered->status === HttpStatusCode::SeeOther->value
            && $read->status === HttpStatusCode::Ok->value
            && str_contains($read->body, $device->credential);
        $said   = $opened ? null : $this->get(AdminPath::Index->to(), $jar);

        if (!$this->check($opened, 'an unlock opens the admin, and its listing names the device', $said)) {
            return false;
        }

        $jar = $jar->keeping($read);

        // 2. The same unlock sent again, with the session that carried its challenge, opens nothing.
        $copied = $before->keeping($this->post(AdminPath::Index->to(), $before, $unlock));
        $this->check(
            $this->get($listing, $copied)->status === HttpStatusCode::SeeOther->value,
            'the same unlock sent again opens nothing',
        );

        // 3. A write with its tap reaches the action; the same post sent again is stale.
        $form   = $this->get($write, $jar);
        $page   = AdminPage::of($form->body);
        $tapped = $jar->keeping($form);
        $fields = SoftwareDevice::posting(
            (string) $page->token(),
            $device->assertion((string) $page->challenge(), $this->origin),
            new FormField(ActionField::Passkey->value, self::NOBODY),
            new FormField(ActionField::Apply->value, 'true'),
        );
        $sent   = $this->post($write, $tapped, $fields);

        $this->check(
            $page->challenge() !== null && $sent->status === HttpStatusCode::UnprocessableContent->value,
            'a write with its tap reaches the action (422: nobody holds that credential, nothing revoked)',
            $sent,
        );
        $this->check(
            $this->post($write, $tapped, $fields)->status === HttpStatusCode::Conflict->value,
            'the same write sent again is refused as stale (409), nothing written',
        );

        $jar = $tapped->keeping($sent);

        // 4. Locking the admin ends every copy of the session. The lock is a form on the listings —
        //    the entrance, to a browser it has let in, is the first of them — not on an action's page.
        $copy   = $jar;
        $shown  = $this->get(AdminPath::Index->to(), $jar);
        $jar    = $jar->keeping($shown);
        $locked = $this->post(
            AdminPath::Index->to(),
            $jar,
            SoftwareDevice::posting(
                (string) AdminPage::of($shown->body)->token(),
                new Collection(FormField::class),
                self::ceremony(EntranceCeremony::Logout),
            ),
        );

        // A lock ends this browser's session outright, so the jar is empty after it; a post the
        // entrance did not take is the same 303 with the cookie left as it was.
        $after = $jar->keeping($locked);

        $this->check(
            $shown->status === HttpStatusCode::Ok->value
                && $locked->status === HttpStatusCode::SeeOther->value
                && $after->isEmpty(),
            'the admin is locked, and this browser\'s session is over',
            $shown->status === HttpStatusCode::Ok->value
                ? $this->get(AdminPath::Index->to(), $after)
                : $shown,
        );

        $dead = $this->get($listing, $copy);

        $this->check(
            $dead->status === HttpStatusCode::SeeOther->value,
            'a copy of the session from before the lock opens nothing',
            $dead,
        );

        $this->output->out(
            $this->failed === 0 ? "\nevery check passed.\n" : sprintf("\n%d check(s) failed.\n", $this->failed),
        );

        return $this->failed === 0;
    }

    /**
     * Prints a line for one check, and what $answer said where it failed; answers whether it passed.
     *
     * @param bool          $passed
     * @param string        $claim
     * @param Response|null $answer
     * @return bool
     */
    public function check(bool $passed, string $claim, ?Response $answer = null): bool
    {
        $this->output->out(sprintf("  %-5s %s\n", $passed ? 'ok' : 'FAIL', $claim));

        if (!$passed) {
            $this->failed++;

            if ($answer !== null) {
                $said = AdminPage::of($answer->body)->text();

                $this->output->out(sprintf(
                    "        answered %d%s\n",
                    $answer->status,
                    $said === '' ? '' : ': ' . $said,
                ));
            }
        }

        return $passed;
    }

    /**
     * Sends $request as it is — a signed call carries no cookie and needs none.
     *
     * @param Request $request
     * @return Response
     * @throws TransportException
     */
    public function send(Request $request): Response
    {
        return $this->transport->send($request);
    }

    /**
     * The entrance, read with $jar: the page, or null where it offers no passkey, and the jar it leaves.
     *
     * @param CookieJar $jar
     * @return array{AdminPage|null, CookieJar}
     * @throws TransportException
     */
    private function entrance(CookieJar $jar): array
    {
        $answer = $this->get(AdminPath::Index->to(), $jar);
        $page   = AdminPage::of($answer->body);
        $offers = $page->token() !== null && $page->challenge() !== null;

        $this->check($offers, 'the entrance offers a passkey', $answer);

        return [$offers ? $page : null, $jar->keeping($answer)];
    }

    /**
     * A GET for $path, sending $jar.
     *
     * @param string    $path
     * @param CookieJar $jar
     * @return Response
     * @throws TransportException
     */
    private function get(string $path, CookieJar $jar): Response
    {
        return $this->transport->send(Request::get($this->at($path), ...$this->headers($jar)));
    }

    /**
     * A form posted to $path, sending $jar.
     *
     * @param string                $path
     * @param CookieJar             $jar
     * @param Collection<FormField> $fields
     * @return Response
     * @throws TransportException
     */
    private function post(string $path, CookieJar $jar, Collection $fields): Response
    {
        return $this->transport->send(Request::form($this->at($path), $fields, ...$this->headers($jar)));
    }

    /**
     * What a browser on the page would send: its origin, and the jar's cookies where it holds any.
     *
     * @param CookieJar $jar
     * @return list<Header>
     */
    private function headers(CookieJar $jar): array
    {
        $origin = new Header(OutboundHeader::Origin, $this->origin);

        return $jar->isEmpty() ? [$origin] : [$origin, $jar->header()];
    }

    /**
     * $path on the local copy.
     *
     * @param string $path
     * @return Url
     */
    private function at(string $path): Url
    {
        return new Url($this->url->render() . $path);
    }

    /**
     * The entrance's ceremony field.
     *
     * @param EntranceCeremony $ceremony
     * @return FormField
     */
    private static function ceremony(EntranceCeremony $ceremony): FormField
    {
        return new FormField(PasskeyFormField::Ceremony->value, $ceremony->value);
    }
}
