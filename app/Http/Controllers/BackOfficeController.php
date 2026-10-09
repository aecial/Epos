<?php

namespace App\Http\Controllers;

use App\Services\MenuHealthService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The back-office hub: links to the menu and stock management pages, plus their health - what's
 * set up, what's misconfigured, and what's running out.
 */
class BackOfficeController extends Controller
{
    public function __construct(private MenuHealthService $menuHealthService) {}

    public function getHub(): Response
    {
        return Inertia::render('backOffice', $this->menuHealthService->Build());
    }
}
