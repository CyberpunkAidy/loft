<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use Illuminate\View\View;

/** The plugin's [loft_plans]. */
class PlanController extends Controller
{
    public function index(Studio $studio): View
    {
        return view('studio.plans', [
            'plans' => $studio->plans()->where('active', true)->with('season')->orderBy('sort')->orderBy('id')->get(),
        ]);
    }
}
