<?php

namespace CookieManagerKit\Scan;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Handler\CurlMultiHandler;

/**
 * Fetches one page for the crawler. The guard runs before every request; redirects are never followed (a redirect
 * is reported as the 3xx it is); the connection is pinned to the address the guard resolved; and the body is
 * capped by declared length, by download progress and by a final length check.
 *
 * `stream` is deliberately not used: with Guzzle's default handler chain it selects the stream handler, which
 * ignores the pinned address.
 */
final class GuzzleFetcher implements Fetcher
{
    public const MAX_BYTES = 2000000;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly UrlGuard $guard,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $timeout = 8,
        private readonly bool $allowUnpinnedTransportForTests = false,
    ) {}

    /**
     * Fails closed: the address pin only works through cURL's CURLOPT_RESOLVE, so any other transport would resolve
     * the host again and could be steered by DNS.
     *
     * @throws FetchFailed
     */
    private function assertPinned(): void
    {
        if ($this->allowUnpinnedTransportForTests) {
            return;
        }

        if (!defined('CURLOPT_RESOLVE') || !$this->usesCurl()) {
            throw new FetchFailed('The request cannot be pinned to the resolved address, so it was not sent.');
        }
    }

    private function usesCurl(): bool
    {
        return $this->client instanceof Client && self::isCurl($this->client->getConfig('handler'));
    }

    /**
     * Whether every route a request can take through this handler ends in a cURL handler. The TLS-fallback and
     * streaming branches of Guzzle's default chain are only taken for the `crypto_method` and `stream` options, and
     * `stream` is forced off in fetch().
     */
    private static function isCurl(mixed $handler): bool
    {
        if ($handler instanceof CurlHandler || $handler instanceof CurlMultiHandler) {
            return true;
        }

        if ($handler instanceof HandlerStack) {
            $inner = (new \ReflectionProperty(HandlerStack::class, 'handler'))->getValue($handler);

            return self::isCurl($inner);
        }

        if ($handler instanceof \Closure) {
            $reflection = new \ReflectionFunction($handler);

            if ($reflection->getClosureScopeClass()?->getName() !== Proxy::class) {
                return false;
            }

            $used = $reflection->getClosureUsedVariables();
            $routes = array_intersect_key($used, ['default' => true, 'sync' => true]);

            if ($routes === []) {
                return false;
            }

            foreach ($routes as $route) {
                if (!self::isCurl($route)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private static function assertIdentity(ResponseInterface $response): void
    {
        foreach ($response->getHeader('Content-Encoding') as $line) {
            foreach (explode(',', $line) as $coding) {
                $coding = strtolower(trim($coding));

                if ($coding !== '' && $coding !== 'identity') {
                    throw new FetchFailed('The response is compressed, which the scanner does not accept.');
                }
            }
        }
    }

    public function fetch(string $url): FetchResult
    {
        $this->guard->check($url);
        $this->assertPinned();

        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            // An environment proxy would carry the request to a host of its choosing, bypassing the pinned address.
            'proxy' => '',
            'verify' => true,
            'stream' => false,
            // A compressed body can expand far beyond the cap (gzip bomb), so none is asked for or decoded.
            'decode_content' => false,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->timeout,
            'headers' => ['User-Agent' => 'CookieManagerKit-Scanner/1.0', 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Encoding' => 'identity'],
            'on_headers' => function (ResponseInterface $response): void {
                self::assertIdentity($response);

                if ((int) $response->getHeaderLine('Content-Length') > $this->maxBytes) {
                    throw new FetchFailed('The response is larger than the scan limit.');
                }
            },
            'progress' => function ($downloadTotal, $downloaded): void {
                if ($downloaded > $this->maxBytes) {
                    throw new FetchFailed('The response is larger than the scan limit.');
                }
            },
        ];

        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [CURLOPT_RESOLVE => [$this->guard->curlResolve()]];
        }

        try {
            $response = $this->client->request('GET', $url, $options);
        } catch (FetchFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new FetchFailed('The request failed (' . $e::class . ').', 0, $e);
        }

        self::assertIdentity($response);

        $size = $response->getBody()->getSize();

        if ($size !== null && $size > $this->maxBytes) {
            throw new FetchFailed('The response is larger than the scan limit.');
        }

        $body = (string) $response->getBody();

        if (strlen($body) > $this->maxBytes) {
            throw new FetchFailed('The response is larger than the scan limit.');
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = array_values(array_map('strval', $values));
        }

        return new FetchResult($response->getStatusCode(), $headers, $body);
    }
}
