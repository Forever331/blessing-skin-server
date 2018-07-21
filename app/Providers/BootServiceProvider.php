<?php

namespace App\Providers;

use DB;
use View;
use Utils;
use Illuminate\Http\Request;
use Composer\Semver\Comparator;
use Illuminate\Support\ServiceProvider;
use App\Exceptions\PrettyPageException;
use App\Http\Controllers\SetupController;
use App\Services\Repositories\OptionRepository;

class BootServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(Request $request)
    {
        // Detect current locale
        $this->app->call('App\Http\Middleware\DetectLanguagePrefer@detect');

        // Skip the installation check when setup or under CLI
        if (! $request->is('setup*') && PHP_SAPI != "cli") {
            $this->checkInstallation();
        }
    }

    protected function checkInstallation()
    {
        // Redirect to setup wizard
        if (! SetupController::checkTablesExist()) {
            return redirect('/setup')->send();
        }

        if (Comparator::greaterThan(config('app.version'), option('version'))) {
            return redirect('/setup/update')->send();
        }

        return true;
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        View::addExtension('tpl', 'blade');

        $this->app->singleton('options',  OptionRepository::class);
    }
}
