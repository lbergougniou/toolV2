<?php

declare(strict_types=1);

namespace ViaDialog\Api\Exception;

use Exception;
use Throwable;

/**
 * Exception générique pour les erreurs liées à l'API ViaDialog
 */
class ApiException extends Exception
{
    /**
     * Constructeur de l'exception ApiException
     *
     * @param string $message Le message d'erreur
     * @param int $code Le code d'erreur (optionnel)
     * @param Throwable|null $previous L'exception précédente si elle existe (optionnel)
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $formattedMessage = 'Erreur API ViaDialog : ' . $message;
        parent::__construct($formattedMessage, $code, $previous);
    }
}
