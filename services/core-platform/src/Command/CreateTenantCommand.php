<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\MembershipRole;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:create-tenant', description: 'Crée un tenant et, éventuellement, son propriétaire')]
final class CreateTenantCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Nom du tenant')
            ->addArgument('slug', InputArgument::REQUIRED, 'Identifiant URL (minuscules, chiffres, tirets)')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Email de l’utilisateur à rattacher comme propriétaire')
            ->setHelp('Exemple : php bin/console app:create-tenant "Acme" acme --owner=admin@example.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = trim((string) $input->getArgument('name'));
        $slug = mb_strtolower(trim((string) $input->getArgument('slug')));

        if ('' === $name || '' === $slug) {
            $io->error('Le nom et le slug sont obligatoires.');

            return Command::FAILURE;
        }

        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $io->error('Le slug doit contenir uniquement des minuscules, des chiffres et des tirets.');

            return Command::FAILURE;
        }

        if (null !== $this->tenants->findOneBySlug($slug)) {
            $io->error(sprintf('Un tenant avec le slug « %s » existe déjà.', $slug));

            return Command::FAILURE;
        }

        $ownerEmail = $input->getOption('owner');
        $owner = null;
        if (null !== $ownerEmail) {
            $owner = $this->users->findOneByEmail((string) $ownerEmail);
            if (null === $owner) {
                $io->error(sprintf('Aucun utilisateur trouvé pour l’email « %s ».', (string) $ownerEmail));

                return Command::FAILURE;
            }
        }

        $tenant = new Tenant($name, $slug);

        $membership = null;
        if (null !== $owner) {
            $membership = new TenantMembership($tenant, $owner, MembershipRole::Owner);
        }

        $this->entityManager->persist($tenant);
        if (null !== $membership) {
            $this->entityManager->persist($membership);
        }
        $this->entityManager->flush();

        $io->success(sprintf('Tenant « %s » créé (slug : %s).', $tenant->getName(), $tenant->getSlug()));

        if (null !== $owner) {
            $io->writeln(sprintf('Propriétaire : %s', $owner->getEmail()));
        }

        return Command::SUCCESS;
    }
}
