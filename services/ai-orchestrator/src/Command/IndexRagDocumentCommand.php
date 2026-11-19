<?php

declare(strict_types=1);

namespace App\Command;

use App\RAG\RagIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'ai:rag:index',
    description: 'Indexe un document RAG pour un tenant avec un contrôle de rôles.',
)]
final class IndexRagDocumentCommand extends Command
{
    public function __construct(private readonly RagIndexer $indexer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tenant', InputArgument::REQUIRED, 'Identifiant UUID du tenant.')
            ->addArgument('file', InputArgument::REQUIRED, 'Chemin du fichier texte à indexer.')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Titre du document (défaut : nom du fichier).')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source affichée (défaut : chemin du fichier).')
            ->addOption('roles', null, InputOption::VALUE_REQUIRED, 'Rôles autorisés séparés par des virgules (défaut : tous les membres).')
            ->addOption('created-by', null, InputOption::VALUE_REQUIRED, 'UUID de l’auteur.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $tenantId = Uuid::fromString((string) $input->getArgument('tenant'));
        } catch (\InvalidArgumentException) {
            $io->error('Le tenant doit être un UUID valide.');

            return Command::INVALID;
        }

        $file = (string) $input->getArgument('file');
        if (!is_file($file) || !is_readable($file)) {
            $io->error(sprintf('Fichier illisible : %s.', $file));

            return Command::FAILURE;
        }

        $content = file_get_contents($file);
        if (false === $content) {
            $io->error('Impossible de lire le fichier.');

            return Command::FAILURE;
        }

        $roles = [];
        $rolesOption = $input->getOption('roles');
        if (\is_string($rolesOption) && '' !== trim($rolesOption)) {
            $roles = array_values(array_filter(array_map('trim', explode(',', $rolesOption))));
        }

        $createdBy = null;
        $createdByOption = $input->getOption('created-by');
        if (\is_string($createdByOption) && '' !== trim($createdByOption)) {
            try {
                $createdBy = Uuid::fromString($createdByOption);
            } catch (\InvalidArgumentException) {
                $io->error('created-by doit être un UUID valide.');

                return Command::INVALID;
            }
        }

        try {
            $result = $this->indexer->index(
                tenantId: $tenantId,
                title: (string) ($input->getOption('title') ?: basename($file)),
                source: (string) ($input->getOption('source') ?: $file),
                content: $content,
                allowedRoles: $roles,
                createdBy: $createdBy,
            );
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s : document %s, %d fragment(s).',
            $result->created ? 'Document indexé' : 'Document déjà indexé',
            $result->document->getId()->toRfc4122(),
            $result->chunks,
        ));

        return Command::SUCCESS;
    }
}
