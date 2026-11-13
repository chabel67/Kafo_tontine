<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\Contracts\AuditEntityLabelResolver;

/**
 * Libellés `entity_label` du journal d'audit, résolus par lot (une requête par type
 * d'entité présent sur la page). Type sans resolver ou entité disparue : pas de libellé
 * (le front se replie sur `entity_type` / `entity_id`).
 */
class AuditEntityLabeler
{
    /** @var array<string, AuditEntityLabelResolver> entity_type => resolver */
    private array $resolvers = [];

    /** @param string[] $entityTypes */
    public function registerResolver(array $entityTypes, AuditEntityLabelResolver $resolver): void
    {
        foreach ($entityTypes as $type) {
            $this->resolvers[$type] = $resolver;
        }
    }

    /**
     * @param  iterable<object{entity_type: ?string, entity_id: ?string}>  $logs
     * @return array<string, array<string, string>>  entity_type → (entity_id → libellé)
     */
    public function labelsFor(iterable $logs): array
    {
        $ids = [];
        foreach ($logs as $log) {
            if ($log->entity_type && $log->entity_id && isset($this->resolvers[$log->entity_type])) {
                $ids[$log->entity_type][$log->entity_id] = true;
            }
        }

        $out = [];
        foreach ($ids as $type => $set) {
            $uuids = array_values(array_filter(array_keys($set), fn ($id) => preg_match('/^[0-9a-f-]{36}$/i', (string) $id)));
            $out[$type] = empty($uuids) ? [] : $this->resolvers[$type]->labels($type, $uuids);
        }

        return $out;
    }

    public function labelFor(?string $entityType, ?string $entityId): ?string
    {
        if (! $entityType || ! $entityId) {
            return null;
        }

        $log = (object) ['entity_type' => $entityType, 'entity_id' => $entityId];

        return $this->labelsFor([$log])[$entityType][$entityId] ?? null;
    }
}
