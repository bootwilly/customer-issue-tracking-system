<?php

namespace App\Exceptions;

use RuntimeException;

/** User-facing business rule violation. Message is shown to the user as-is. */
class BusinessException extends RuntimeException {}
