<?php

declare(strict_types=1);

namespace App\Aggregation;

use InvalidArgumentException;
use MongoDB\BSON\Binary;
use MongoDB\Builder\Query;
use MongoDB\Builder\Type\FieldQueryInterface;

use function array_filter;
use function intdiv;
use function pack;
use function sprintf;
use function str_repeat;

/**
 * A slice of the UUID space, used to split work by station into equally sized parts.
 *
 * UUIDs are stored as 16 byte binaries of subtype 4. The server orders binaries by length,
 * then subtype, then byte for byte, so for UUIDs this is the same as big-endian numeric
 * order. This lets a slice be expressed as a plain `$gte`/`$lt` range, which can use an
 * index on the field; the `$bits*` operators cannot.
 *
 * Only the first four bytes of a UUID are used to find the boundaries, which is plenty
 * of resolution for any realistic number of parts.
 */
final class UuidRange
{
    private function __construct(
        public readonly ?Binary $from,
        public readonly ?Binary $to,
    ) {
    }

    /**
     * The index-th of the given number of equally sized parts. The first part has no lower
     * bound and the last has no upper bound, so that the parts cover all values.
     */
    public static function part(int $index, int $parts): self
    {
        if ($parts < 1 || $index < 0 || $index >= $parts) {
            throw new InvalidArgumentException(sprintf('Invalid part %d of %d.', $index, $parts));
        }

        return new self(
            $index === 0 ? null : self::boundary($index, $parts),
            $index === $parts - 1 ? null : self::boundary($index + 1, $parts),
        );
    }

    public function toFieldQuery(): FieldQueryInterface
    {
        if ($this->isUnbounded()) {
            throw new InvalidArgumentException('Cannot build a query for an unbounded range.');
        }

        return Query::fieldQuery(...array_filter([
            $this->from !== null ? Query::gte($this->from) : null,
            $this->to !== null ? Query::lt($this->to) : null,
        ]));
    }

    public function isUnbounded(): bool
    {
        return $this->from === null && $this->to === null;
    }

    private static function boundary(int $index, int $parts): Binary
    {
        $prefix = intdiv($index << 32, $parts);

        return new Binary(pack('N', $prefix) . str_repeat("\0", 12), Binary::TYPE_UUID);
    }
}
