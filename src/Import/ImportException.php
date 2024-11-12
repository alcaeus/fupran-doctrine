<?php

namespace App\Import;

use RuntimeException;
use function sprintf;

class ImportException extends RuntimeException
{
    public static function fileNotFound(string $fileOrDirectory): static
    {
        return new static(sprintf('File or directory "%s" does not exist', $fileOrDirectory));
    }

    public static function cannotImportFile(string $fileOrDirectory): static
    {
        throw new static(sprintf('Cannot import file "%s"', $fileOrDirectory));
    }

    public static function fileNotReadable(string $file): static
    {
        throw new static(sprintf('Cannot open file "%s"', $file));
    }
}
