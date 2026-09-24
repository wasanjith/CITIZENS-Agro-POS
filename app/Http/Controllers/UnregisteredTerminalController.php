<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class UnregisteredTerminalController extends Controller
{
    public function __invoke(): View
    {
        return view('terminal.unregistered');
    }
}
