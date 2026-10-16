<?php

namespace CookieManagerKit\Scan;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Downloads the Open Cookie Database CSV from one fixed HTTPS URL. The URL is a constant (never admin input or
 * request data), redirects are not followed, and the body is size-capped.
 */
final class GithubSnapshot implements SnapshotSource
{
    public const URL = 'https://raw.githubusercontent.com/jkwakman/Open-Cookie-Database/master/open-cookie-database.csv';
    public const MAX_BYTES = ClassificationFile::MAX_BYTES;

    public function __construct(private readonly ClientInterface $client) {}

    private static function assertIdentity(ResponseInterface $response): void
    {
        foreach ($response->getHeader('Content-Encoding') as $line) {
            foreach (explode(',', $line) as $coding) {
                $coding = strtolower(trim($coding));

                if ($coding !== '' && $coding !== 'identity') {
                    throw new FetchFailed('The download is compressed, which is not accepted.');
                }
            }
        }
    }

    public function fetch(): string
    {
        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            // An environment proxy must not carry the request elsewhere.
            'proxy' => '',
            'verify' => true,
            'stream' => false,
            // A hard bound: the sink throws on the write that would pass the cap, whatever the transport reports.
            'sink' => new CappedSink(self::MAX_BYTES),
            // A compressed body can expand far beyond the cap (gzip bomb), so none is asked for or decoded.
            'decode_content' => false,
            'timeout' => 20,
            'connect_timeout' => 10,
            'headers' => ['User-Agent' => 'CookieManagerKit-Scanner/1.0', 'Accept-Encoding' => 'identity'],
            'on_headers' => static function (ResponseInterface $response): void {
                self::assertIdentity($response);

                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                    throw new FetchFailed('The download is larger than the limit.');
                }
            },
            'progress' => static function ($downloadTotal, $downloaded): void {
                if ($downloaded > self::MAX_BYTES) {
                    throw new FetchFailed('The download is larger than the limit.');
                }
            },
        ];

        try {
            $response = $this->client->request('GET', self::URL, $options);
        } catch (FetchFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new FetchFailed('The download failed (' . $e::class . ').', 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new FetchFailed('The download answered ' . $response->getStatusCode() . '.');
        }

        self::assertIdentity($response);

        $size = $response->getBody()->getSize();

        if ($size !== null && $size > self::MAX_BYTES) {
            throw new FetchFailed('The download is larger than the limit.');
        }

        $body = (string) $response->getBody();

        if (strlen($body) > self::MAX_BYTES) {
            throw new FetchFailed('The download is larger than the limit.');
        }

        return $body;
    }
}
