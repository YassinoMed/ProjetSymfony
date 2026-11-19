<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum AuditAction: string
{
    case MembershipAdd = 'membership.add';
    case MembershipRoleChange = 'membership.role_change';
    case MembershipRemove = 'membership.remove';
    case AuditViewed = 'audit.view';
    case FeatureDefinitionCreate = 'feature_definition.create';
    case FeatureDefinitionUpdate = 'feature_definition.update';
    case FeatureDefinitionVersionCreate = 'feature_definition.version';
    case DynamicRecordCreate = 'dynamic_record.create';
    case DynamicRecordUpdate = 'dynamic_record.update';
    case DynamicRecordDelete = 'dynamic_record.delete';
    case DynamicRecordTransition = 'dynamic_record.transition';

    public function label(): string
    {
        return match ($this) {
            self::MembershipAdd => 'Ajout d\'un membre',
            self::MembershipRoleChange => 'Changement de rôle',
            self::MembershipRemove => 'Retrait d\'un membre',
            self::AuditViewed => 'Consultation du journal d\'audit',
            self::FeatureDefinitionCreate => 'Création d\'une fonctionnalité',
            self::FeatureDefinitionUpdate => 'Mise à jour des métadonnées',
            self::FeatureDefinitionVersionCreate => 'Publication d\'une version',
            self::DynamicRecordCreate => 'Création d\'un enregistrement',
            self::DynamicRecordUpdate => 'Mise à jour d\'un enregistrement',
            self::DynamicRecordDelete => 'Suppression d\'un enregistrement',
            self::DynamicRecordTransition => 'Transition de workflow',
        };
    }
}
