<?php

namespace App\Http\Controllers;

use App\Services\System\SystemCheck;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Is the control plane itself working. Admin-only: the fixes are commands to
 * run on the machine hosting it.
 */
class SystemController extends Controller
{
    public function __construct(private readonly SystemCheck $checks) {}

    public function show(Request $request): Response
    {
        $live = $request->boolean('live');

        return Inertia::render('control-plane/system', [
            'checks' => $live ? $this->checks->full() : $this->checks->quick(),
            'live' => $live,
        ]);
    }
}
