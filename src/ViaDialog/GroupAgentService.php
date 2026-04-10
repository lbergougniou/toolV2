<?php

namespace ViaDialog;

use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ApiException;

/**
 * Service pour la gestion des agents d'un groupe ViaDialog
 * Encapsule la validation et la mise à jour des agents par groupe
 */
class GroupAgentService
{
    public function __construct(private ViaDialogClient $client) {}

    /**
     * Met à jour la liste des agents d'un groupe (remplace la liste complète)
     *
     * @param int   $groupId Identifiant du groupe
     * @param array $agents  Liste au format [['viaAgentRefDTO' => ['id' => int, 'label' => string], 'priority' => int], ...]
     *
     * @return array Réponse de l'API
     * @throws \InvalidArgumentException Si les paramètres sont invalides
     * @throws ApiException En cas d'erreur API
     */
    public function updateGroupAgents(int $groupId, array $agents): array
    {
        if ($groupId <= 0) {
            throw new \InvalidArgumentException('groupId invalide');
        }
        if (empty($agents)) {
            throw new \InvalidArgumentException("La liste d'agents ne peut pas être vide");
        }
        foreach ($agents as $agent) {
            if (empty($agent['viaAgentRefDTO']['id'])) {
                throw new \InvalidArgumentException('Chaque agent doit avoir un viaAgentRefDTO.id');
            }
            $p = (int) ($agent['priority'] ?? 0);
            if ($p < 1 || $p > 6) {
                throw new \InvalidArgumentException('La priorité doit être comprise entre 1 et 6');
            }
        }

        return $this->client->updateGroupAgents($groupId, $agents);
    }
}
