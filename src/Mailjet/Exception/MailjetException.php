// src/Mailjet/Exception/MailjetException.php
namespace App\Mailjet\Exception;

class MailjetException extends \Exception {
    protected $errorCode;
    protected $httpCode;
    protected $context;
    
    public function __construct($message, $errorCode = '', $httpCode = 0, $context = [], \Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->httpCode = $httpCode;
        $this->context = $context;
    }
    
    // Getters pour les propriétés spécifiques
}