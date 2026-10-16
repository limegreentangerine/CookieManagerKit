<?php

namespace CookieManagerKit\Scan;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

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
    ) {}

    public function fetch(string $url): FetchResult
    {
        $this->guard->check($url);

        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            // An environment proxy would carry the request to a host of its choosing, bypassing the pinned address.
            'proxy' => '',
            'timeout' => $this->timeout,
            'connect_timeout' => $this->timeout,
            'headers' => ['User-Agent' => 'CookieManagerKit-Scanner/1.0', 'Accept' => 'text/html,application/xhtml+xml'],
            'on_headers' => function (ResponseInterface $response): void {
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
