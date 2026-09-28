<?php

namespace Toggly\WordPress\Http;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Raised when the WordPress HTTP API cannot complete a PSR-18 request.
 */
final class WordPressHttpClientException extends \RuntimeException implements ClientExceptionInterface
{
}
