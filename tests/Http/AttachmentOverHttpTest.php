<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * Files go up as a multipart form and come back as a stream.
 *
 * They live outside the document root, so there is no address that serves one
 * without passing the membership check first -- which is the whole reason they
 * are served by the application rather than by nginx. Someone else's link is a
 * 404, not a 403.
 */
final class AttachmentOverHttpTest extends AcceptanceTestCase
{
    private const CONTENT = "Private upload bytes over HTTP.\n";
    private const TICKET  = '/projects/1/tickets/NAF-1';

    public function testAnUploadedFileComesBackByteForByteAsADownload(): void
    {
        $link = $this->upload();

        $download = $this->alice->request($link);

        $this->assertSame(200, $download['status']);
        $this->assertSame(self::CONTENT, $download['body'], 'the bytes changed on the way');
        $this->assertMatchesRegularExpression(
            '/content-disposition:\s*attachment;/i',
            $download['headers'],
            'the file was offered to be rendered rather than saved',
        );

        $this->remove($link);
    }

    public function testSomebodyElseGetsNothingFromTheLink(): void
    {
        $link = $this->upload();

        $this->assertSame(404, $this->bob->request($link)['status'], 'a stranger could download it');
        $this->assertSame(
            404,
            $this->bob->request($link . '/delete', [], $this->bob->jsonHeaders($this->bob->token(
                $this->page($this->bob, '/projects/' . self::OTHER_PROJECT),
            )))['status'],
            'a stranger could delete it',
        );

        $this->remove($link);
    }

    public function testDeletingItTakesTheAddressWithIt(): void
    {
        $link = $this->upload();

        $this->remove($link);

        $this->assertSame(404, $this->alice->request($link)['status'], 'the file is still served');
    }

    /** Upload a file the way the browser's form does, and return its address. */
    private function upload(): string
    {
        $boundary = 'nafinity-test-boundary';
        $token    = $this->alice->token($this->page($this->alice, self::TICKET));
        $body     = "--{$boundary}\r\nContent-Disposition: form-data; name=\"_csrf\"\r\n\r\n{$token}\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"attachment\";"
            . " filename=\"acceptance.txt\"\r\nContent-Type: text/plain\r\n\r\n"
            . self::CONTENT
            . "\r\n--{$boundary}--\r\n";

        $response = $this->alice->request(self::TICKET . '/attachments', $body, [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Accept: application/json',
        ]);
        $this->assertSame(200, $response['status'], 'the upload was refused');

        return $this->firstMatch(
            '#href="([^"]+/attachments/\d+)"#',
            $this->page($this->alice, self::TICKET),
            'the attachment link',
        );
    }

    /**
     * Asked as the page's own script asks: a browser form would be answered with
     * a redirect back to the ticket, which says nothing about whether it worked.
     */
    private function remove(string $link): void
    {
        $token    = $this->alice->token($this->page($this->alice, self::TICKET));
        $response = $this->alice->request($link . '/delete', [], $this->alice->jsonHeaders($token));

        $this->assertSame(200, $response['status'], 'the owner could not delete the file');
    }
}
