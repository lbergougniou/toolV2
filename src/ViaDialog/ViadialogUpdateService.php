<?php

namespace ViaDialog;

use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;

/**
 * Service pour la mise à jour en masse des services ViaDialog
 * Permet d'appliquer des modifications à plusieurs services simultanément
 */
class ViadialogUpdateService
{
    private ViaDialogClient $client;

    public function __construct(ViaDialogClient $client)
    {
        $this->client = $client;
    }

    /**
     * Récupère les informations d'un service
     *
     * @param int $serviceId ID du service
     * @return array|null Données du service ou null si erreur
     */
    public function getService(int $serviceId): ?array
    {
        try {
            // Utilisation de getServiceDetails qui retourne un tableau
            $service = $this->client->getServiceDetails((string)$serviceId);
            return $service ?: null;
        } catch (ApiException $e) {
            error_log("Erreur lors de la récupération du service {$serviceId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Applique des modifications à un ou plusieurs services
     *
     * @param array $serviceIds Liste des IDs de services (ex: [123, 456])
     * @param array $modifications Modifications à appliquer (ex: ['maxUser' => 10])
     * @return array Résultats de chaque mise à jour
     */
    public function applyModifications(array $serviceIds, array $modifications): array
    {
        $results = [];

        foreach ($serviceIds as $serviceId) {
            try {
                // Récupération du service (retourne un tableau)
                $service = $this->client->getServiceDetails((string)$serviceId);

                if (!$service) {
                    $results[] = [
                        'serviceId' => $serviceId,
                        'success' => false,
                        'error' => 'Service non trouvé'
                    ];
                    continue;
                }

                // Application des modifications
                foreach ($modifications as $key => $value) {
                    if ($key === 'script') {
                        // Traitement spécial pour les scripts VIACONTACT
                        if (isset($service['product']) && $service['product'] === 'VIACONTACT') {
                            if (isset($service['sdaLists']) && is_array($service['sdaLists'])) {
                                foreach ($service['sdaLists'] as &$sda) {
                                    if (isset($value['scriptId'])) {
                                        $sda['scriptId'] = $value['scriptId'];
                                    }
                                    if (isset($value['scriptVersion'])) {
                                        $sda['scriptVersion'] = $value['scriptVersion'];
                                    }
                                }
                            }
                        }
                    } else {
                        // Modification standard
                        $service[$key] = $value;
                    }
                }

                // Mise à jour du service via l'API
                $updated = $this->client->updateService($service);

                if ($updated) {
                    $results[] = [
                        'serviceId' => $serviceId,
                        'success' => true,
                        'message' => 'Service mis à jour avec succès'
                    ];
                } else {
                    $results[] = [
                        'serviceId' => $serviceId,
                        'success' => false,
                        'error' => 'Échec de la mise à jour'
                    ];
                }

                // Pause pour éviter de surcharger l'API
                usleep(100000); // 100ms

            } catch (ApiException $e) {
                $errorMsg = $e->getMessage();
                // Vérification si c'est un timeout
                if (strpos($errorMsg, 'cURL error 28') !== false || strpos($errorMsg, 'timeout') !== false) {
                    $errorMsg = 'Timeout: La requête a pris trop de temps. Vérifiez votre connexion ou réessayez.';
                }
                $results[] = [
                    'serviceId' => $serviceId,
                    'success' => false,
                    'error' => $errorMsg
                ];
                error_log("Erreur API pour le service {$serviceId}: " . $e->getMessage());
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                // Vérification si c'est un timeout
                if (strpos($errorMsg, 'cURL error 28') !== false || strpos($errorMsg, 'timeout') !== false) {
                    $errorMsg = 'Timeout: La requête a pris trop de temps. Vérifiez votre connexion ou réessayez.';
                }
                $results[] = [
                    'serviceId' => $serviceId,
                    'success' => false,
                    'error' => 'Erreur inattendue: ' . $errorMsg
                ];
                error_log("Erreur inattendue pour le service {$serviceId}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Valide les IDs de services
     *
     * @param string $serviceIdsStr Chaîne d'IDs séparés par des virgules
     * @return array Liste d'IDs valides
     */
    public function parseServiceIds(string $serviceIdsStr): array
    {
        $ids = explode(',', $serviceIdsStr);
        $validIds = [];

        foreach ($ids as $id) {
            $id = trim($id);
            if (is_numeric($id) && $id > 0) {
                $validIds[] = (int) $id;
            }
        }

        return $validIds;
    }

    /**
     * Prépare les modifications à partir des données du formulaire
     *
     * @param array $formData Données du formulaire
     * @return array Modifications formatées pour l'API
     */
    public function prepareModifications(array $formData): array
    {
        $modifications = [];

        // Traitement des champs standard
        $standardFields = [
            'maxUser',
            'name',
            'label',
            'status',
            'product',
            'maxLine'
        ];

        foreach ($standardFields as $field) {
            if (isset($formData[$field]) && $formData[$field] !== '') {
                // Conversion en entier pour les champs numériques
                if (in_array($field, ['maxUser', 'maxLine'])) {
                    $modifications[$field] = (int) $formData[$field];
                } else {
                    $modifications[$field] = $formData[$field];
                }
            }
        }

        // Traitement spécial pour les scripts
        if (isset($formData['scriptId']) || isset($formData['scriptVersion'])) {
            $modifications['script'] = [];
            if (isset($formData['scriptId']) && $formData['scriptId'] !== '') {
                $modifications['script']['scriptId'] = $formData['scriptId'];
            }
            if (isset($formData['scriptVersion']) && $formData['scriptVersion'] !== '') {
                $modifications['script']['scriptVersion'] = $formData['scriptVersion'];
            }
        }

        return $modifications;
    }

    /**
     * Génère un rapport de résultats au format texte
     *
     * @param array $results Résultats des mises à jour
     * @return string Rapport formaté
     */
    public function generateReport(array $results): string
    {
        $report = "=== Rapport de mise à jour des services ===\n\n";
        $successCount = 0;
        $failureCount = 0;

        foreach ($results as $result) {
            if ($result['success']) {
                $successCount++;
                $report .= "✓ Service {$result['serviceId']}: {$result['message']}\n";
            } else {
                $failureCount++;
                $report .= "✗ Service {$result['serviceId']}: {$result['error']}\n";
            }
        }

        $report .= "\n=== Résumé ===\n";
        $report .= "Total: " . count($results) . "\n";
        $report .= "Succès: {$successCount}\n";
        $report .= "Échecs: {$failureCount}\n";

        return $report;
    }
}
