<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\UrlGuard;
use GuzzleHttp\Handler\MockHandler;
use CookieManagerKit\Scan\UnsafeUrl;
use CookieManagerKit\Scan\FetchFailed;
use CookieManagerKit\Scan\GuzzleFetcher;

final class GuzzleFetcherTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function fetcher(array $queue, int $maxBytes = 2000000): GuzzleFetcher
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $guard = UrlGuard::forSite('https://example.com', static fn(string $host): string => '203.0.113.7');

        return new GuzzleFetcher(new Client(['handler' => $stack]), $guard, $maxBytes, 8, true);
    }

    public function testReturnsStatusLowerCasedHeadersAndBody(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Set-Cookie' => ['a=1', 'b=2']], '<p>hi</p>')]);

        $result = $fetcher->fetch('https://example.com/');

        $this->assertSame(200, $result->status);
        $this->assertSame('<p>hi</p>', $result->body);
        $this->assertTrue($result->isHtml());
        $this->assertSame(['a=1', 'b=2'], $result->cookies());
        $this->assertSame('text/html; charset=utf-8', $result->header('Content-Type'));
    }

    public function testNeverFollowsRedirectsAndPinsTheAddress(): void
    {
        $fetcher = $this->fetcher([new Response(302, ['Location' => 'https://evil.example.net/'])]);

        $result = $fetcher->fetch('https://example.com/');

        $this->assertSame(302, $result->status);
        $this->assertCount(1, $this->history);
        $options = $this->history[0]['options'];
        $this->assertFalse($options['allow_redirects']);
        $this->assertFalse($options['http_errors']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(8, $options['timeout']);

        if (defined('CURLOPT_RESOLVE')) {
            $this->assertSame(['example.com:443:203.0.113.7'], $options['curl'][CURLOPT_RESOLVE]);
        }
    }

    public function testRefusesAnOffHostUrlWithoutSendingARequest(): void
    {
        $fetcher = $this->fetcher([new Response(200, [], 'x')]);

        try {
            $fetcher->fetch('https://evil.example.net/');
            $this->fail('fetched an off-host URL');
        } catch (UnsafeUrl) {
            $this->assertCount(0, $this->history);
        }
    }

    public function testABodyOverTheCapIsRefused(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html'], str_repeat('a', 101))], 100);

        $this->expectException(FetchFailed::class);

        $fetcher->fetch('https://example.com/');
    }

    public function testADeclaredLengthOverTheCapIsRefusedBeforeReadingTheBody(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html', 'Content-Length' => '5000'], 'short')], 100);

        $this->expectException(FetchFailed::class);

        $fetcher->fetch('https://example.com/');
    }

    public function testTransportErrorsBecomeFetchFailed(): void
    {
        $fetcher = $this->fetcher([new \GuzzleHttp\Exception\ConnectException('timed out', new \GuzzleHttp\Psr7\Request('GET', 'https://example.com/'))]);

        $this->expectException(FetchFailed::class);

        $fetcher->fetch('https://example.com/');
    }

    public function testANonHtmlResponseIsNotHtml(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'application/pdf'], '%PDF')]);

        $this->assertFalse($fetcher->fetch('https://example.com/a.pdf')->isHtml());
    }

    public function testRefusesUrlsThatSmuggleAnotherHostWithoutSendingARequest(): void
    {
        foreach (['https://example.com./', 'https://example.com\\@evil.example.net/', 'https://169.254.169.254/'] as $url) {
            $fetcher = $this->fetcher([new Response(200, [], 'x')]);

            try {
                $fetcher->fetch($url);
                $this->fail('fetched ' . $url);
            } catch (UnsafeUrl) {
                $this->assertCount(0, $this->history);
            }
        }
    }

    public function testAskForAndAcceptOnlyUncompressedBodies(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html'], 'ok')]);

        $fetcher->fetch('https://example.com/');

        $this->assertSame('identity', $this->history[0]['request']->getHeaderLine('Accept-Encoding'));
        $this->assertFalse($this->history[0]['options']['decode_content']);
        $this->assertTrue($this->history[0]['options']['verify']);
        $this->assertFalse($this->history[0]['options']['stream']);
    }

    public function testACompressedResponseIsRefused(): void
    {
        foreach (['gzip', 'deflate', 'br', 'identity, gzip'] as $coding) {
            $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html', 'Content-Encoding' => $coding], 'x')]);

            try {
                $fetcher->fetch('https://example.com/');
                $this->fail('accepted ' . $coding);
            } catch (FetchFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnIdentityEncodedResponseIsAccepted(): void
    {
        $fetcher = $this->fetcher([new Response(200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'identity'], 'ok')]);

        $this->assertSame('ok', $fetcher->fetch('https://example.com/')->body);
    }

    public function testFailsClosedWhenTheTransportCannotPinTheAddress(): void
    {
        $guard = UrlGuard::forSite('https://example.com', static fn(string $host): string => '203.0.113.7');
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], 'x')]))]);

        $this->expectException(FetchFailed::class);

        (new GuzzleFetcher($client, $guard))->fetch('https://example.com/');
    }

    public function testFailsClosedForAStreamHandler(): void
    {
        $guard = UrlGuard::forSite('https://example.com', static fn(string $host): string => '203.0.113.7');
        $client = new Client(['handler' => HandlerStack::create(new \GuzzleHttp\Handler\StreamHandler())]);

        $this->expectException(FetchFailed::class);

        (new GuzzleFetcher($client, $guard))->fetch('https://example.com/');
    }

    public function testAcceptsTheCurlHandlerAndGuzzlesDefaultChain(): void
    {
        if (!function_exists('curl_multi_exec')) {
            $this->markTestSkipped('ext-curl is not available.');
        }

        $guard = UrlGuard::forSite('https://example.com', static fn(string $host): string => '203.0.113.7');
        $probe = new \ReflectionMethod(GuzzleFetcher::class, 'usesCurl');

        foreach ([new Client(), new Client(['handler' => HandlerStack::create(new \GuzzleHttp\Handler\CurlHandler())])] as $client) {
            $this->assertTrue($probe->invoke(new GuzzleFetcher($client, $guard)));
        }
    }
}
