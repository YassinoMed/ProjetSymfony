<?php

declare(strict_types=1);

namespace App\RAG;

final class RagRoles
{
    public const array ALLOWED = ['owner', 'admin', 'member', 'auditor'];

    /**
     * @param array<array-key, mixed> $roles
     *
     * @return list<string>
     */
    public static function normalize(array $roles): array
    {
        $normalized = [];
        foreach ($roles as $role) {
            if (!\is_string($role)) {
                throw new \InvalidArgumentException('Chaque rôle RAG doit être une chaîne.');
            }

            $role = mb_strtolower(trim($role));
            if (!\in_array($role, self::ALLOWED, true)) {
                throw new \InvalidArgumentException(sprintf('Rôle RAG inconnu : %s.', $role));
            }

            $normalized[] = $role;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }
}
