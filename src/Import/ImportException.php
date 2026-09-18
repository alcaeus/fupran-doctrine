<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

use function sprintf;

final class ImportException extends RuntimeException
{
    public static function fileNotFound(string $fileOrDirectory): self
    {
        return new self(sprintf('File or directory "%s" does not exist', $fileOrDirectory));
    }

    public static function cannotImportFile(string $fileOrDirectory): self
    {
        return new self(sprintf('Cannot import file "%s"', $fileOrDirectory));
    }

    public static function fileNotReadable(string $file): self
    {
        return new self(sprintf('Cannot open file "%s"', $file));
    }
}
