<?php

declare(strict_types=1);

namespace App\RAG;

/**
 * Détecte les tentatives d'injection de prompt dans du contenu non fiable.
 *
 * Les documents indexés ne sont jamais exécutés : ils sont traités comme des
 * données. Ce scanner ajoute une seconde barrière en signalant les motifs
 * connus et les caractères invisibles utilisés pour masquer des instructions.
 */
final class PromptInjectionScanner
{
    private const array PATTERNS = [
        'ignore_previous' => '/\b(ignore|disregard|forget)\s+(all\s+)?(the\s+)?(previous|prior|above)\s+(instructions?|prompts?|rules?)\b/iu',
        'fr_ignore_instructions' => '/\b(ignore[rz]?|oublie[rz]?|passe[rz]?\s+outre)\s+(les\s+|toutes\s+les\s+)?(instructions?|consignes?|r[eè]gles?)\b/iu',
        'system_prompt' => '/\b(system|developer)\s+(prompt|message|instructions?)\b/iu',
        'role_override' => '/\b(you are now|act as|tu es maintenant|agis comme|comporte-toi comme)\b/iu',
        'jailbreak' => '/\b(jailbreak|developer mode|dan mode|do anything now)\b/iu',
        'exfiltration' => '/\b(reveal|print|show|leak|exfiltrat\w*|r[eé]v[eè]le[rz]?|affiche[rz]?)\b.{0,40}\b(secret|prompt|instructions?|api[ _-]?key|token|password)\b/iu',
        'credentials' => '/\b(api[ _-]?key|secret[ _-]?key|access[ _-]?token|private[ _-]?key)\b/iu',
    ];

    /**
     * @return array{severity: 'none'|'high', matches: list<string>}
     */
    public function scan(string $text): array
    {
        $matches = [];

        foreach (self::PATTERNS as $label => $pattern) {
            if (1 === preg_match($pattern, $text)) {
                $matches[] = $label;
            }
        }

        if (1 === preg_match('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', $text)) {
            $matches[] = 'invisible_unicode';
        }

        $matches = array_values(array_unique($matches));

        return [
            'severity' => [] === $matches ? 'none' : 'high',
            'matches' => \array_slice($matches, 0, 8),
        ];
    }
}
