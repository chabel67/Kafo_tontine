<?php

namespace App\Modules\Audit\Domain\Contracts;

/**
 * Fournit le libellé humain des entités citées par le journal d'audit (`entity_type`,
 * `entity_id`) : nom du membre, référence de paiement ou de prêt, nom de campagne…
 * Le module Audit ne lit jamais les modèles des autres modules (DA-09) : chaque module
 * enregistre ses resolvers dans ModuleServiceProvider (même principe que
 * AccountOwnerLabelResolver pour le ledger).
 */
interface AuditEntityLabelResolver
{
    /**
     * @param  string   $entityType  Valeur `audit_logs.entity_type` (un resolver peut en servir plusieurs)
     * @param  string[] $ids
     * @return array<string, string>  id → libellé (ids inconnus absents)
     */
    public function labels(string $entityType, array $ids): array;
}
