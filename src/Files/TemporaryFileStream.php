<?php

declare(strict_types=1);

namespace OpenYam\HyperfExcel\Files;

use Psr\Http\Message\StreamInterface;

final class TemporaryFileStream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    public function __construct(private readonly TemporaryFile $file)
    {
        $resource = fopen($file->path(), 'rb');
        if ($resource === false) {
            throw new \RuntimeException('Unable to open generated spreadsheet.');
        }
        $this->resource = $resource;
    }

    public function __toString(): string
    {
        try {
            $this->rewind();
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }
    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        } $this->resource = null;
        $this->file->delete();
    }
    /** @return resource */
    public function detach()
    {
        $resource = $this->requireResource();
        $position = $this->tell();
        $detached = tmpfile();
        if (! is_resource($detached)) {
            throw new \RuntimeException('Unable to detach spreadsheet stream.');
        }
        if (rewind($resource) === false || stream_copy_to_stream($resource, $detached) === false || fseek($detached, $position) !== 0) {
            fclose($detached);
            throw new \RuntimeException('Unable to copy detached spreadsheet stream.');
        }
        fclose($resource);
        $this->resource = null;
        $this->file->delete();
        return $detached;
    }
    public function getSize(): ?int
    {
        $size = @filesize($this->file->path());
        return $size === false ? null : $size;
    }
    public function tell(): int
    {
        $position = ftell($this->requireResource());
        if ($position === false) {
            throw new \RuntimeException('Unable to determine stream position.');
        } return $position;
    }
    public function eof(): bool
    {
        return feof($this->requireResource());
    }
    public function isSeekable(): bool
    {
        return is_resource($this->resource);
    }
    public function seek($offset, $whence = SEEK_SET): void
    {
        if (fseek($this->requireResource(), $offset, $whence) !== 0) {
            throw new \RuntimeException('Unable to seek stream.');
        }
    }
    public function rewind(): void
    {
        $this->seek(0);
    }
    public function isWritable(): bool
    {
        return false;
    }
    public function write($string): int
    {
        throw new \RuntimeException('Spreadsheet download streams are read-only.');
    }
    public function isReadable(): bool
    {
        return is_resource($this->resource);
    }
    public function read($length): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('Read length must be greater than zero.');
        }
        $data = fread($this->requireResource(), $length);
        if ($data === false) {
            throw new \RuntimeException('Unable to read stream.');
        } return $data;
    }
    public function getContents(): string
    {
        $data = stream_get_contents($this->requireResource());
        if ($data === false) {
            throw new \RuntimeException('Unable to read stream.');
        } return $data;
    }
    public function getMetadata($key = null): mixed
    {
        $metadata = stream_get_meta_data($this->requireResource());
        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }
    public function __destruct()
    {
        $this->close();
    }

    /** @return resource */
    private function requireResource()
    {
        if (! is_resource($this->resource)) {
            throw new \RuntimeException('Stream is detached.');
        }
        return $this->resource;
    }
}
