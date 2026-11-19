<?php

declare(strict_types=1);

namespace App\Feature;

use App\Entity\DynamicRecord;

/**
 * Présentation des enregistrements dynamiques (liste et détail).
 */
final class FeaturePresenter
{
    public function label(FeatureSchema $schema, string $field): string
    {
        return match ($field) {
            'status' => 'Statut',
            'created_at' => 'Créé le',
            'updated_at' => 'Modifié le',
            'created_by' => 'Créé par',
            default => $schema->getField($field)->label,
        };
    }

    /**
     * @return array<string, string>
     */
    public function columns(FeatureSchema $schema): array
    {
        $columns = [];

        foreach ($schema->listFields() as $field) {
            $columns[$field] = $this->label($schema, $field);
        }

        return $columns;
    }

    /**
     * @return array<string, string>
     */
    public function row(DynamicRecord $record, FeatureSchema $schema, WorkflowDefinition $workflow): array
    {
        $row = [];

        foreach ($schema->listFields() as $field) {
            $row[$field] = $this->value($record, $schema, $workflow, $field);
        }

        return $row;
    }

    public function value(
        DynamicRecord $record,
        FeatureSchema $schema,
        WorkflowDefinition $workflow,
        string $field,
    ): string {
        return match ($field) {
            'status' => $workflow->stateLabel($record->getStatus()),
            'created_at' => $record->getCreatedAt()->format('d/m/Y H:i'),
            'updated_at' => $record->getUpdatedAt()->format('d/m/Y H:i'),
            'created_by' => $record->getCreatedBy()?->getEmail() ?? '—',
            default => $this->fieldValue($schema->getField($field), $record->getData()[$field] ?? null),
        };
    }

    /**
     * @return array<string, string>
     */
    public function detailRows(DynamicRecord $record, FeatureSchema $schema, WorkflowDefinition $workflow): array
    {
        $rows = [];

        foreach ($schema->fieldNames() as $field) {
            $rows[$this->label($schema, $field)] = $this->value($record, $schema, $workflow, $field);
        }

        $rows['Statut'] = $workflow->stateLabel($record->getStatus());
        $rows['Version du schéma'] = 'v'.$record->getSchemaVersion();
        $rows['Créé par'] = $record->getCreatedBy()?->getEmail() ?? '—';
        $rows['Créé le'] = $record->getCreatedAt()->format('d/m/Y H:i');
        $rows['Modifié le'] = $record->getUpdatedAt()->format('d/m/Y H:i');

        return $rows;
    }

    private function fieldValue(FeatureField $field, mixed $value): string
    {
        if (null === $value || '' === $value || [] === $value) {
            return '—';
        }

        if ('boolean' === $field->type) {
            return $value ? 'Oui' : 'Non';
        }

        if ('array' === $field->type) {
            if (!\is_array($value)) {
                return (string) $value;
            }

            return implode(', ', array_map(static fn (mixed $item): string => (string) $item, $value));
        }

        if (null !== $field->format && 'date' === $field->format && \is_string($value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return false === $date ? $value : $date->format('d/m/Y');
        }

        if (\is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }

        if (\is_string($value) && [] !== $field->enum) {
            return ucfirst($value);
        }

        return (string) $value;
    }
}
