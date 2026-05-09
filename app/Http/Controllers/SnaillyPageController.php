<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class SnaillyPageController extends Controller
{
    public function __invoke(Request $request): View
    {
        $allowedPages = [
            'home', 'login', 'register', 'login-child', 'child-dashboard', 'child-logs',
            'dashboard', 'children', 'log-activity', 'rules', 'access-requests', 'schedule',
            'report', 'streak-calendar', 'setting', 'about', 'blocked',
        ];

        $page = $request->routeIs('snailly.blocked')
            ? 'blocked'
            : (string) $request->query('page', 'home');

        if (! in_array($page, $allowedPages, true)) {
            $page = 'home';
        }

        $config = [
            'apiBase' => 'laravel-local-backend',
            'appName' => 'Snailly Kids',
            'version' => '1.0-laravel',
        ];

        return view('snailly.app', compact('page', 'config'));
    }
}
