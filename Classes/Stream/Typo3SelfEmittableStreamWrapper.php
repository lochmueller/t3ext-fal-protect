<?php

declare(strict_types=1);

namespace Causal\FalProtect\Stream;

use Lochmueller\HttpRange\Stream\EmitStreamInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\SelfEmittableStreamInterface;

class Typo3SelfEmittableStreamWrapper implements SelfEmittableStreamInterface
{
    public function __construct(protected StreamInterface $stream)
    {
    }

    public function emit(): void
    {
        if ($this->stream instanceof EmitStreamInterface) {
            $this->stream->emit();
        } else {
            echo $this->__toString();
        }
    }

    public function __toString(): string
    {
        return (string) $this->stream;
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function detach()
    {
        $this->stream->detach();

        return null;
    }

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function tell(): ?int
    {
        return $this->stream->tell();
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->stream->rewind();
    }

    public function isWritable()
    {
        return $this->stream->isWritable();
    }

    public function write(string $string): int
    {
        return $this->stream->write($string);
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function read(int $length): string
    {
        return $this->stream->read($length);
    }

    public function getContents(): string
    {
        return $this->stream->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->stream->getMetadata();
    }
}
