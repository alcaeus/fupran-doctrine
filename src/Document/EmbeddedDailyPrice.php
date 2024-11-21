<?php

declare(strict_types=1);

namespace App\Document;

use App\Document\Partial\AbstractDailyPrice;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;

#[EmbeddedDocument]
class EmbeddedDailyPrice extends AbstractDailyPrice
{
}
