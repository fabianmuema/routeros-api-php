<?php

namespace RouterOS\Streams;

use RouterOS\Interfaces\StreamInterface;
use RouterOS\Exceptions\StreamException;

/**
 * class ResourceStream
 *
 * Stream using a resource (socket, file, pipe etc.)
 *
 * @package RouterOS
 * @since   0.9
 */
class ResourceStream implements StreamInterface
{
    protected $stream;

    /**
     * ResourceStream constructor.
     *
     * @param $stream
     */
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException(sprintf('Argument must be a valid resource type. %s given.', gettype($stream)));
        }

        // TODO: Should we verify the resource type?
        $this->stream = $stream;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \RouterOS\Exceptions\StreamException when length parameter is invalid
     * @throws \InvalidArgumentException when the stream have been totally read and read method is called again
     */
    public function read(int $length): string
    {
        if ($length <= 0) {
            throw new \InvalidArgumentException('Cannot read zero or negative count of bytes from a stream');
        }

        if (!is_resource($this->stream)) {
            throw new StreamException('Stream is not writable');
        }

        // Set stream to non-blocking mode
        stream_set_blocking($this->stream, false);

        $read = [$this->stream];
        $write = null;
        $except = null;

        // Wait up to 5 seconds for data (10,000,000 microseconds)
        $selectResult = stream_select($read, $write, $except, 5, 0);

        if ($selectResult === false) {
            throw new StreamException('Stream select error occurred');
        }

        if ($selectResult === 0) {
            throw new StreamException('Stream timed out after 5 seconds');
        }

        // Set back to blocking mode for actual read
        stream_set_blocking($this->stream, true);

        $result = stream_get_contents($this->stream, $length);

        if ($result === false) {
            throw new StreamException("Error reading $length bytes");
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \RouterOS\Exceptions\StreamException when not possible to write bytes
     */
    public function write(string $string, int $length = null): int
    {
        if (null === $length) {
            $length = strlen($string);
        }

        if (!is_resource($this->stream)) {
            throw new StreamException('Stream is not writable');
        }

        // Set write timeout
        stream_set_timeout($this->stream, 10); // 10 seconds

        // Check if stream is writable
        $write = [$this->stream];
        $read = null;
        $except = null;
        
        $selectResult = stream_select($read, $write, $except, 5, 0);
        
        if ($selectResult === false) {
            throw new StreamException('Stream select error');
        }
        
        if ($selectResult === 0) {
            throw new StreamException('Stream write timeout');
        }

        $written = 0;
        $attempts = 0;
        $maxAttempts = 3;

        while ($written < $length && $attempts < $maxAttempts) {
            $result = fwrite($this->stream, substr($string, $written), $length - $written);
            
            if ($result === false) {
                throw new StreamException("Error writing to stream on attempt " . ($attempts + 1));
            }
            
            if ($result === 0) {
                $attempts++;
                usleep(100000); // Wait 100ms before retry
                continue;
            }
            
            $written += $result;
            $attempts = 0; // Reset attempts on successful write
        }

        if ($written < $length) {
            throw new StreamException("Could not write all data. Wrote $written of $length bytes");
        }

        return $written;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \RouterOS\Exceptions\StreamException when not possible to close the stream
     */
    public function close(): void
    {
        $hasBeenClosed = false;

        if (null !== $this->stream) {
            $hasBeenClosed = @fclose($this->stream);
            $this->stream  = null;
        }

        if (false === $hasBeenClosed) {
            throw new StreamException('Error closing stream');
        }
    }
}
