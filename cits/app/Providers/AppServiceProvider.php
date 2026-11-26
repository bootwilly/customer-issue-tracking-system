<?php

namespace App\Providers;

use App\Services\AuthService;
use App\Services\NotificationService;
use App\Support\CurrentProject;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentProject::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admins can do and see everything.
        Gate::before(fn ($user) => $user->is_admin ? true : null);

        View::composer('layouts.app', function ($view) {
            $user = Auth::user();
            $current = app(CurrentProject::class);
            $view->with('navProjects', $user ? app(AuthService::class)->projectsFor($user) : collect());
            $view->with('currentProject', $current->get());
            $view->with('unreadCount', $user ? app(NotificationService::class)->unreadCount($user) : 0);
        });
    }
}
