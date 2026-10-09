<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The back-office landing page: how today is going, at a glance. Managers/admins only, like the
 * rest of the back office (EnsureBackOfficeUser).
 */
class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function getDashboard(): Response
    {
        return Inertia::render('dashboard', $this->dashboardService->Build(now()));
    }
}
