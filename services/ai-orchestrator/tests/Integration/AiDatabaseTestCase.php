<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base des tests PostgreSQL de l'orchestrateur IA.
 *
 * Les tests ne s'exécutent pas dans une transaction englobante car les
 * services métier ouvrent leurs propres transactions (verrous pessimistes,
 * indexation idempotente). Les tables sont donc vidées avant et après chaque
 * test.
 */
abstract class AiDatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $entityManager;

    protected Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();

        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            self::markTestSkipped('PostgreSQL de test indisponible : '.$exception->getMessage());
        }

        try {
            $tablesExist = $this->connection->createSchemaManager()->tablesExist([
                'ai_requests', 'ai_runs', 'ai_proposals', 'rag_documents', 'rag_chunks',
            ]);
        } catch (\Throwable $exception) {
            self::markTestSkipped('Schéma de test indisponible : '.$exception->getMessage());
        }

        if (!$tablesExist) {
            self::markTestSkipped('Schéma Phase 4 absent : exécuter les migrations avant les tests.');
        }

        $this->truncate();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->truncate();
            $this->entityManager->clear();
        }

        parent::tearDown();
    }

    protected function truncate(): void
    {
        $this->connection->executeStatement(
            'TRUNCATE TABLE ai_proposals, ai_runs, rag_chunks, rag_documents, ai_requests RESTART IDENTITY CASCADE',
        );

        try {
            $this->connection->executeStatement('TRUNCATE TABLE messenger_messages RESTART IDENTITY');
        } catch (\Throwable) {
            // La table Messenger est optionnelle selon l'environnement.
        }
    }
}
