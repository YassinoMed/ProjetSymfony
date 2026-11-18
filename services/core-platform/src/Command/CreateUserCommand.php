<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-user', description: 'Crée un utilisateur de la plateforme')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse email de l’utilisateur')
            ->addArgument('password', InputArgument::OPTIONAL, 'Mot de passe (demandé de façon masquée si absent)')
            ->addOption('tenant-admin', null, InputOption::VALUE_NONE, 'Attribue ROLE_TENANT_ADMIN')
            ->addOption('platform-admin', null, InputOption::VALUE_NONE, 'Attribue ROLE_PLATFORM_ADMIN')
            ->addOption('auditor', null, InputOption::VALUE_NONE, 'Attribue ROLE_AUDITOR')
            ->setHelp('Exemple : php bin/console app:create-user admin@example.com --platform-admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        if ('' === $email) {
            $io->error('L’adresse email est obligatoire.');

            return Command::FAILURE;
        }

        if (null !== $this->users->findOneByEmail($email)) {
            $io->error(sprintf('Un utilisateur avec l’email « %s » existe déjà.', $email));

            return Command::FAILURE;
        }

        $password = $input->getArgument('password');
        if (!is_string($password) || '' === $password) {
            if (!$input->isInteractive()) {
                $io->error('Le mot de passe est obligatoire en mode non interactif.');

                return Command::FAILURE;
            }

            $question = (new Question('Mot de passe : '))
                ->setHidden(true)
                ->setHiddenFallback(false)
                ->setValidator(static function (mixed $value): string {
                    if (!is_string($value) || \strlen($value) < 8) {
                        throw new \RuntimeException('Le mot de passe doit contenir au moins 8 caractères.');
                    }

                    return $value;
                });

            $password = $this->getHelper('question')->ask($input, $output, $question);
        }

        \assert(is_string($password));

        $roles = [];
        if ($input->getOption('tenant-admin')) {
            $roles[] = 'ROLE_TENANT_ADMIN';
        }
        if ($input->getOption('platform-admin')) {
            $roles[] = 'ROLE_PLATFORM_ADMIN';
        }
        if ($input->getOption('auditor')) {
            $roles[] = 'ROLE_AUDITOR';
        }

        $user = new User($email, '', $roles);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Utilisateur « %s » créé avec les rôles : %s', $email, implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
