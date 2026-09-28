<?php

namespace Toggly\WordPress\Http;

use Psr\Http\Message\StreamInterface;

/**
 * Simple stream implementation for WordPress
 */
class WordPressStream implements StreamInterface
{
    private string $content;
    private int $position = 0;
    private bool $closed = false;

    public function __construct(string $content = '')
    {
        $this->content = $content;
    }

    public function __toString(): string
    {
        return $this->closed ? '' : $this->content;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->content = '';
        $this->position = 0;
    }

    public function detach()
    {
        $this->close();
        return null;
    }

    public function getSize(): ?int
    {
        if ($this->closed) {
            return null;
        }

        return strlen($this->content);
    }

    public function tell(): int
    {
        $this->ensureOpen();
        return $this->position;
    }

    public function eof(): bool
    {
        $this->ensureOpen();
        return $this->position >= strlen($this->content);
    }

    public function isSeekable(): bool
    {
        return $this->closed === false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->ensureOpen();
        if ($whence === SEEK_SET) {
            $this->position = $offset;
        } elseif ($whence === SEEK_CUR) {
            $this->position += $offset;
        } elseif ($whence === SEEK_END) {
            $this->position = strlen($this->content) + $offset;
        }
    }

    public function rewind(): void
    {
        $this->ensureOpen();
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return !$this->closed;
    }

    public function write($string): int
    {
        $this->ensureOpen();
        $length = strlen($string);
        $this->content = substr_replace($this->content, $string, $this->position, $length);
        $this->position += $length;
        return $length;
    }

    public function isReadable(): bool
    {
        return $this->closed === false;
    }

    public function read($length): string
    {
        $this->ensureOpen();
        $result = substr($this->content, $this->position, $length);
        $this->position += strlen($result);
        return $result;
    }

    public function getContents(): string
    {
        $this->ensureOpen();
        $result = substr($this->content, $this->position);
        $this->position = strlen($this->content);
        return $result;
    }

    public function getMetadata($key = null)
    {
        return null;
    }

    private function ensureOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('Cannot operate on a closed stream.');
        }
    }
}
