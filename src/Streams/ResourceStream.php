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
        if (!$this->isValidStream($stream)) {
            throw new \InvalidArgumentException(sprintf('Argument must be a valid stream resource. %s given.', gettype($stream)));
        }

        // TODO: Should we verify the resource type?
        $this->stream = $stream;
    }

    /**
     * Check if the given value is a valid stream (resource or object)
     *
     * @param mixed $stream
     * @return bool
     */
    private function isValidStream($stream): bool
    {
        // PHP 8.0+ streams are objects, not resources
        if (is_resource($stream)) {
            return true;
        }
        
        // Check for stream wrapper objects in PHP 8.0+
        if (is_object($stream)) {
            $className = get_class($stream);
            // Socket, OpenSSLSocket, and other stream wrapper objects
            return in_array($className, ['Socket', 'OpenSSLSocket', 'Shmop', 'SysvMessageQueue', 'SysvSemaphore', 'SysvSharedMemory'], true);
        }
        
        return false;
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
            throw new \InvalidArgumentException('Cannot read zero ot negative count of bytes from a stream');
        }

        if (!$this->isValidStream($this->stream)) {
            throw new StreamException('Stream is not readable');
        }

        $result = fread($this->stream, $length);

        // Stream in blocking mode timed out - use stream_get_meta_data instead of deprecated socket_get_status
        $metadata = stream_get_meta_data($this->stream);
        if ($metadata['timed_out'] ?? false) {
            throw new StreamException('Stream timed out');
        }

        if (false === $result) {
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

        if (!$this->isValidStream($this->stream)) {
            throw new StreamException('Stream is not writable');
        }

        $result = fwrite($this->stream, $string, $length);

        if (false === $result) {
            throw new StreamException("Error writing $length bytes");
        }

        return $result;
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
