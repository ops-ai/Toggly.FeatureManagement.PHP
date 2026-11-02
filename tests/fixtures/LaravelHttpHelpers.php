<?php

/**
 * Minimal Laravel HTTP helpers for middleware unit tests outside a full app.
 */
if (!function_exists('redirect')) {
    /**
     * @param string $to
     */
    function redirect($to)
    {
        return new \Illuminate\Http\RedirectResponse($to);
    }
}

if (!function_exists('abort')) {
    /**
     * @param int $code
     * @param string $message
     * @param array<string, string> $headers
     * @return never
     */
    function abort($code, $message = '', array $headers = [])
    {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($code, $message, null, $headers);
    }
}
