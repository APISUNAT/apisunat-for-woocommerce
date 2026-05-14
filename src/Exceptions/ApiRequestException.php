<?php
namespace Atm\Apisunatwp\Exceptions;

class ApiRequestException extends ApiException {
    public function isTransient(): bool {
        $code = $this->getCode();
        // Network errors (wp_error path) come through with code = 0.
        if ($code === 0) {
            return true;
        }
        // 408 Request Timeout, 429 Too Many Requests, 5xx Server Error are retryable.
        return $code === 408
            || $code === 429
            || ($code >= 500 && $code < 600);
    }
}
