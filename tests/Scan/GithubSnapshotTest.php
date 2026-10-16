<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Handler\MockHandler;
use CookieManagerKit\Scan\FetchFailed;
use CookieManagerKit\Scan\GithubSnapshot;

final class GithubSnapshotTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    /**
     * @param list<Response> $queue
     */
    private function snapshot(array $queue): GithubSnapshot
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new GithubSnapshot(new Client(['handler' => $stack]));
    }

    public function testTheUrlIsAFixedHttpsConstant(): void
    {
        $this->assertStringStartsWith('https://raw.githubusercontent.com/', GithubSnapshot::URL);
    }

    public function testReturnsTheBodyOfAFixedUrlWithoutFollowingRedirects(): void
    {
        $snapshot = $this->snapshot([new Response(200, [], 'csv-body')]);

        $this->assertSame('csv-body', $snapshot->fetch());
        $this->assertSame(GithubSnapshot::URL, (string) $this->history[0]['request']->getUri());
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testANon200IsRefused(): void
    {
        $this->expectException(FetchFailed::class);

        $this->snapshot([new Response(302, ['Location' => 'https://evil.example.net/'])])->fetch();
    }

    public function testAnOversizeBodyIsRefused(): void
    {
        $this->expectException(FetchFailed::class);

        $this->snapshot([new Response(200, [], str_repeat('a', GithubSnapshot::MAX_BYTES + 1))])->fetch();
    }

    public function testCompressedResponsesAreRefusedAndNeverDecoded(): void
    {
        $snapshot = $this->snapshot([new Response(200, ['Content-Encoding' => 'gzip'], 'x')]);

        try {
            $snapshot->fetch();
            $this->fail('accepted a compressed response');
        } catch (FetchFailed) {
            $options = $this->history[0]['options'];
            $this->assertFalse($options['decode_content']);
            $this->assertSame('identity', $this->history[0]['request']->getHeaderLine('Accept-Encoding'));
        }
    }

    public function testRequestIsHardened(): void
    {
        $this->snapshot([new Response(200, [], 'ok')])->fetch();
        $options = $this->history[0]['options'];

        $this->assertTrue($options['verify']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(20, $options['timeout']);
    }

    public function testATransportErrorBecomesFetchFailed(): void
    {
        $this->expectException(FetchFailed::class);

        $this->snapshot([new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('GET', GithubSnapshot::URL))])->fetch();
    }
}
