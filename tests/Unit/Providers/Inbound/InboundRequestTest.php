<?php

declare(strict_types=1);

namespace Grav\Plugin\Email\Tests\Unit\Providers\Inbound;

use Grav\Plugin\Email\Providers\Inbound\Address;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * The request an inbound receiver is handed, from each of its constructors,
 * and the Address value object the parsed messages carry.
 */
final class InboundRequestTest extends TestCase
{
    public function testFromServerRequestKeepsTheRawBodyAndLowerCasesHeaders(): void
    {
        $raw = "From: a@example.com\r\nSubject: x\r\n\r\nbody\r\n";
        $psr = (new ServerRequest('post', 'https://example.com/_helpdesk/inbound/generic/abc?x=1', [
            'Content-Type' => 'message/rfc822',
            'X-Grav-Signature' => 't=1,v1=ab',
        ], $raw, '1.1', ['REMOTE_ADDR' => '198.51.100.4']))->withQueryParams(['x' => '1']);

        $request = InboundRequest::fromServerRequest($psr);

        $this->assertSame('POST', $request->method);
        $this->assertSame('/_helpdesk/inbound/generic/abc', $request->path);
        $this->assertSame(['x' => '1'], $request->query);
        $this->assertSame($raw, $request->body);
        $this->assertSame('t=1,v1=ab', $request->header('X-GRAV-SIGNATURE'));
        $this->assertSame('message/rfc822', $request->contentType());
        $this->assertSame('198.51.100.4', $request->remoteAddress);
        $this->assertSame(\strlen($raw), $request->size());
        $this->assertNull($request->json());
        $this->assertSame($raw, (string)$psr->getBody(), 'the stream is rewound for the next reader');
    }

    public function testFromServerRequestCarriesFormFieldsAndFiles(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, 'PDFDATA');
        $file = new UploadedFile(Stream::create(fopen($tmp, 'rb')), 7, UPLOAD_ERR_OK, 'invoice.pdf', 'application/pdf');

        $psr = (new ServerRequest('POST', '/in', ['Content-Type' => 'multipart/form-data; boundary=x']))
            ->withParsedBody(['sender' => 'a@example.com', 'body-plain' => 'Hello'])
            ->withUploadedFiles(['attachment-1' => $file, 'more' => ['one' => $file]]);

        $request = InboundRequest::fromServerRequest($psr);

        $this->assertSame('', $request->body);
        $this->assertSame('a@example.com', $request->field('sender'));
        $this->assertSame('', $request->field('missing'));
        $this->assertCount(2, $request->files);
        $this->assertSame('attachment-1', $request->files[0]->field);
        $this->assertSame('more[one]', $request->files[1]->field);
        $this->assertSame('invoice.pdf', $request->files[0]->filename);
        $this->assertSame('application/pdf', $request->files[0]->type);
        $this->assertSame(7, $request->files[0]->size);
        $this->assertSame('PDFDATA', $request->files[0]->contents());
        $this->assertSame(\strlen('a@example.com') + 5 + 7 + 7, $request->size());
        @unlink($tmp);
    }

    public function testFromGlobals(): void
    {
        $saved = [$_SERVER, $_GET, $_POST, $_FILES];
        try {
            $_SERVER = [
                'REQUEST_METHOD' => 'post',
                'REQUEST_URI' => '/_helpdesk/inbound/mailgun/s3cret?a=b',
                'CONTENT_TYPE' => 'multipart/form-data; boundary=zz',
                'CONTENT_LENGTH' => '1234',
                'HTTP_X_MAILGUN_SIGNATURE' => 'sig',
                'REMOTE_ADDR' => '203.0.113.9',
            ];
            $_GET = ['a' => 'b'];
            $_POST = ['timestamp' => '1758620000'];
            $_FILES = [
                'attachment-1' => ['name' => 'a.txt', 'type' => 'text/plain', 'size' => 3, 'tmp_name' => '/tmp/none', 'error' => 0],
                'multi' => ['name' => ['x.png'], 'type' => ['image/png'], 'size' => [9], 'tmp_name' => ['/tmp/none2'], 'error' => [0]],
            ];

            $request = InboundRequest::fromGlobals();

            $this->assertSame('POST', $request->method);
            $this->assertSame('/_helpdesk/inbound/mailgun/s3cret', $request->path);
            $this->assertSame('sig', $request->header('x-mailgun-signature'));
            $this->assertSame('1234', $request->header('content-length'));
            $this->assertSame('multipart/form-data', $request->contentType());
            $this->assertSame('1758620000', $request->field('timestamp'));
            $this->assertSame('203.0.113.9', $request->remoteAddress);
            $this->assertCount(2, $request->files);
            $this->assertSame('multi[0]', $request->files[1]->field);
            $this->assertNull($request->files[0]->contents(), 'a missing temp file reads as null');
        } finally {
            [$_SERVER, $_GET, $_POST, $_FILES] = $saved;
        }
    }

    public function testJson(): void
    {
        $this->assertSame(['a' => 1], (new InboundRequest(body: '{"a":1}'))->json());
        $this->assertNull((new InboundRequest(body: '{broken'))->json());
        $this->assertNull((new InboundRequest(body: ''))->json());
    }

    public function testAddressParsing(): void
    {
        $this->assertSame('a@example.com', Address::parse('a@example.com')->email);
        $this->assertSame('', Address::parse('a@example.com')->name);

        $a = Address::parse('"Smith, John" <John.Smith@Example.COM>');
        $this->assertSame('John.Smith@Example.COM', $a->email);
        $this->assertSame('Smith, John', $a->name);
        $this->assertSame('john.smith@example.com', $a->normalized());
        $this->assertSame('example.com', $a->domain());
        $this->assertSame('John.Smith', $a->local());
        $this->assertNull($a->detail());
        $this->assertSame('"Smith, John" <John.Smith@Example.COM>', (string)$a);

        $this->assertSame('t8f2k', Address::parse('<support+t8f2k@example.com>')->detail());
        $this->assertSame('x@y.z', Address::parse('<@relay.example:x@y.z>')->email);
        $this->assertTrue(Address::parse('')->isEmpty());
        $this->assertTrue(Address::parse('undisclosed-recipients:;')->isEmpty());

        $list = Address::parseList('a@x.com, "B (Boss)" <b@x.com> (work), Friends: c@x.com, d@x.com;, e@x.com');
        $this->assertSame(['a@x.com', 'b@x.com', 'c@x.com', 'd@x.com', 'e@x.com'], array_map(static fn (Address $a) => $a->email, $list));
        $this->assertSame('B (Boss)', $list[1]->name);
    }
}
