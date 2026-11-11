<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class AppLayout extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?string $title = null,
        public string $pageTitle = 'Dashboard',
        public string $activeRole = 'staff',
    ) {}

    /**
     * Get the view that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
