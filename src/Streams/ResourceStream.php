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
    public function read(int $length): string {
        $data = '';
        $received = 0;
        
        // Set socket to non-blocking
        stream_set_blocking($this->stream, false);
        
        $startTime = microtime(true);
        
        while ($received < $length) {
            // Check timeout
            if ((microtime(true) - $startTime) > 60) {
                throw new StreamException("Read timeout after {$received}/{$length} bytes");
            }
            
            // Wait for data
            $read = [$this->stream];
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, 1);
            
            if ($ready === false) {
                throw new StreamException("stream_select failed");
            } elseif ($ready === 0) {
                continue; // Timeout, check again
            }
            
            // Use stream_socket_recvfrom for better performance
            $chunk = @stream_socket_recvfrom($this->stream, $length - $received);
            
            if ($chunk === false) {
                $error = error_get_last();
                throw new StreamException("Read failed: " . $error['message']);
            } elseif ($chunk === '') {
                // Check if connection closed
                if (feof($this->stream)) {
                    throw new StreamException("Connection closed by RouterOS");
                }
                usleep(1000); // 1ms delay
                continue;
            }
            
            $data .= $chunk;
            $received += strlen($chunk);
        }
        
        return $data;
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
