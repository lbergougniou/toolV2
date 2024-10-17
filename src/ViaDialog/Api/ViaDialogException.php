<?php

declare(strict_types=1);

namespace ViaDialog\Api\Exception;

use Exception;

/**
 * Exception générique pour les erreurs liées à l'API
 */
class ApiException extends Exception
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Erreur API ViaDialog : " . $message, $code, $previous);
    }
}

/**
 * Exception spécifique pour les erreurs d'authentification
 */
class AuthenticationException extends ApiException
{
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Erreur d'authentification : " . $message, $code, $previous);
    }
}
