<?php

namespace App\Exceptions;

use RuntimeException;

class QrGenerationException extends RuntimeException
{
    public static function gdMissing(): self
    {
        return new self('QR generation requires PHP GD extension. Please enable GD on the server.');
    }
}
