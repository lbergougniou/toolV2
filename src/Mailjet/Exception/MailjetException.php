<?php
/**
 * MailjetException.php - Exception levée lors des appels à l'API Mailjet
 *
 * Le code de l'exception (getCode()) est le code HTTP de la réponse, ce qui permet
 * à MailjetClient::retryApiCall() de décider si l'appel peut être réessayé.
 */

namespace App\Mailjet\Exception;

class MailjetException extends \Exception {
    /**
     * @var string Code d'erreur applicatif (ex: LIST_CREATION_ERROR)
     */
    protected $errorCode;

    /**
     * @var int Code HTTP de la réponse Mailjet (0 si non applicable)
     */
    protected $httpCode;

    /**
     * @var array Contexte de l'erreur
     */
    protected $context;

    /**
     * @var string Motif d'erreur renvoyé par Mailjet (ErrorMessage / ErrorInfo)
     */
    protected $apiMessage;

    public function __construct($message, $errorCode = '', $httpCode = 0, $context = [], ?\Throwable $previous = null, $apiMessage = '') {
        parent::__construct($message, (int) $httpCode, $previous);
        $this->errorCode = $errorCode;
        $this->httpCode = (int) $httpCode;
        $this->context = $context;
        $this->apiMessage = $apiMessage;
    }

    /**
     * Clés API absentes
     */
    public static function missingCredentials() {
        return new self("Clés API Mailjet manquantes (MAILJET_API_KEY / MAILJET_SECRET_KEY)", 'MISSING_CREDENTIALS');
    }

    /**
     * Construit l'exception à partir d'une requête cURL échouée
     * Le motif renvoyé par Mailjet est extrait du corps JSON de la réponse.
     *
     * @param \CurlHandle $ch Poignée cURL (avant curl_close)
     * @param string $message Message de contexte
     * @param string $errorCode Code d'erreur applicatif
     * @param array $context Contexte
     * @param string|false $response Corps de la réponse
     */
    public static function fromCurlResponse($ch, $message, $errorCode, array $context = [], $response = '') {
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $apiMessage = "Erreur réseau: " . curl_error($ch);
        } else {
            $apiMessage = self::extractApiMessage($response);
        }

        $fullMessage = $message . ($httpCode ? " (HTTP {$httpCode})" : '') . ($apiMessage ? " : {$apiMessage}" : '');

        return new self($fullMessage, $errorCode, $httpCode, $context, null, $apiMessage);
    }

    /**
     * Réponse Mailjet au format inattendu
     */
    public static function invalidResponseFormat($response, $expectedPath) {
        return new self(
            "Réponse Mailjet inattendue (champ {$expectedPath} absent)",
            'INVALID_RESPONSE_FORMAT',
            0,
            ['response' => substr((string) $response, 0, 500)]
        );
    }

    /**
     * Extrait le motif d'erreur d'un corps de réponse Mailjet
     * Format Mailjet : {"ErrorInfo": "...", "ErrorMessage": "...", "StatusCode": 400}
     */
    private static function extractApiMessage($response) {
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return trim(substr(strip_tags((string) $response), 0, 200));
        }

        $parts = array_filter([
            $data['ErrorMessage'] ?? null,
            $data['ErrorInfo'] ?? null,
            $data['Errors'][0]['ErrorMessage'] ?? null,
        ]);

        return implode(' - ', array_unique($parts));
    }

    public function getErrorCode() {
        return $this->errorCode;
    }

    public function getHttpCode() {
        return $this->httpCode;
    }

    public function getContext() {
        return $this->context;
    }

    public function getApiMessage() {
        return $this->apiMessage;
    }
}
