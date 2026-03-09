<?php

declare(strict_types=1);

namespace PointerDev\AIChat\Exceptions;

use RuntimeException;

class AIChatRequestException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $responseBody,
        public readonly mixed $responseData = null,
        ?string $message = null
    ) {
        $detail = $message ?: $this->buildMessage($status, $responseData, $responseBody);
        parent::__construct($detail, $status);
    }

    private function buildMessage(int $status, mixed $responseData, string $responseBody): string
    {
        if (is_array($responseData) && isset($responseData['detail']) && is_scalar($responseData['detail'])) {
            return (string) $responseData['detail'];
        }

        if (trim($responseBody) !== '') {
            return $responseBody;
        }

        return sprintf('AI Chat request failed with status %d.', $status);
    }
}
