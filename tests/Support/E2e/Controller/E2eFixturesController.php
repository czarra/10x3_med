<?php

namespace App\Tests\Support\E2e\Controller;

use App\Tests\Support\E2e\E2eFixtures;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-support endpoint for the Playwright suite. Wired (service + route) only
 * for APP_ENV=e2e — see the `when@e2e` blocks in config/services.yaml and
 * config/routes.yaml — so it can never be reached in dev/prod. Sits outside the
 * firewall's access_control rules by path and needs no auth by design.
 */
#[When('e2e')]
final class E2eFixturesController extends AbstractController
{
    #[Route('/__e2e__/reset', name: 'e2e_reset', methods: ['POST'])]
    public function reset(E2eFixtures $fixtures): JsonResponse
    {
        return new JsonResponse($fixtures->reset());
    }

    #[Route('/__e2e__/reset-dashboard-scenario', name: 'e2e_reset_dashboard_scenario', methods: ['POST'])]
    public function resetDashboardScenario(E2eFixtures $fixtures): JsonResponse
    {
        return new JsonResponse($fixtures->seedDashboardBaseDoseScenario());
    }
}
