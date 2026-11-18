<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(TenantContext $tenantContext): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'tenant' => $tenantContext->getTenant(),
            'membership' => $tenantContext->getMembership(),
        ]);
    }
}
