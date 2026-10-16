<?php

namespace CookieManagerKit\Scan;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use GuzzleHttp\Psr7\StreamDecoratorTrait;

/**
 * A download sink that refuses to hold more than a fixed number of bytes: the write that would pass the cap throws,
 * so memory is bounded by the cap however the transport reports progress. Buffered in php://temp.
 */
final class CappedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $written = 0;

    public function __construct(private readonly int $maxBytes)
    {
        $this->stream = Utils::streamFor(fopen('php://temp', 'r+'));
    }

    /**
     * @throws FetchFailed
     */
    public function write($string): int
    {
        if ($this->written + strlen($string) > $this->maxBytes) {
            throw new FetchFailed('The download is larger than the limit.');
        }

        $this->written += strlen($string);

        return $this->stream->write($string);
    }
}
