<?php

namespace App\Providers;

use App\Services\AuthService;
use App\Services\NotificationService;
use App\Services\SanitizerService;
use App\Support\CurrentProject;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
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
        $this->app->singleton(SanitizerService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Stored rich text is sanitized when saved; @richtext sanitizes it again when shown, so rows written by
        // older code, imports or direct database edits cannot inject script into a page.
        Blade::directive('richtext', fn (string $expr) => "<?php echo app(\\App\\Services\\SanitizerService::class)->clean((string) ($expr)); ?>");

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
