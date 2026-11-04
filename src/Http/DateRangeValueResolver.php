<?php

declare(strict_types=1);

namespace App\Http;

use App\DateRange;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

use function is_string;
use function sprintf;

final class DateRangeValueResolver implements ValueResolverInterface
{
    public function __construct(
        #[Autowire('%app.chart.max_range_days%')]
        private readonly int $maxDays,
    ) {
    }

    /** @return iterable<DateRange> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $attribute = $argument->getAttributesOfType(MapDateRange::class, ArgumentMetadata::IS_INSTANCEOF)[0] ?? null;
        if ($argument->getType() !== DateRange::class || $attribute === null) {
            return [];
        }

        try {
            return [
                DateRange::parse(
                    self::getDay($request, 'from'),
                    self::getDay($request, 'to'),
                    $attribute->defaultDays,
                    $this->maxDays,
                ),
            ];
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }
    }

    private static function getDay(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new BadRequestHttpException(sprintf('The "%s" parameter must be a string.', $name));
        }

        return $value;
    }
}
