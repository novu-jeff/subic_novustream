<?php

namespace App\Providers;

use App\Models\Admin;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Request $request): void
    {
        Paginator::useBootstrapFive();

        if(Request::is('admin/*')) {

            Auth::shouldUse('admins');

            Gate::define('superadmin', function ($user) {
                return $user->user_type === 'superadmin';
            });

            Gate::define('admin', function ($user) {
                return in_array($user->user_type, ['admin', 'superadmin']);
            });

            Gate::define('technician', function ($user) {
                return $user->user_type === 'technician';
            });

            Gate::define('cashier', function ($user) {
                return $user->user_type === 'cashier';
            });

            Gate::define('inspector', function ($user) {
                return $user->user_type === 'inspector';
            });

        }

        if(Request::is('concessionaire/*')) {
            Auth::shouldUse('web');

            Gate::define('concessionaire', function ($user) {
                return true;
            });



        }



    }
}
