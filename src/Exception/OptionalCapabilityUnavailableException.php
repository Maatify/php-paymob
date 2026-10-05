<?php

declare(strict_types=1);

namespace Maatify\Paymob\Exception;

use Maatify\Exceptions\Exception\Unsupported\UnsupportedOperationMaatifyException;

final class OptionalCapabilityUnavailableException extends UnsupportedOperationMaatifyException implements PaymobExceptionInterface {}
