<?php

declare(strict_types=1);

namespace ViaDialog\Api\Exception;

use Throwable;

/**
 * Exception spécifique pour les erreurs d'authentification
 */
class AuthenticationException extends ApiException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            "Erreur d'authentification : " . $message,
            $code,
            $previous
        );
    }
}
