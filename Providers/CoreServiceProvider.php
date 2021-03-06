<?php namespace Modules\Core\Providers;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Maatwebsite\Sidebar\SidebarManager;
use Modules\Core\Console\PublishModuleAssetsCommand;
use Modules\Core\Console\PublishThemeAssetsCommand;
use Modules\Core\Foundation\Theme\ThemeManager;
use Modules\Core\Sidebar\AdminSidebar;
use Pingpong\Modules\Module;

class CoreServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * @var string
     */
    protected $prefix = 'asgard';

    /**
     * The filters base class name.
     *
     * @var array
     */
    protected $middleware = [
        'Core' => [
            'permissions'           => 'PermissionMiddleware',
            'auth.admin'            => 'AdminMiddleware',
            'public.checkLocale'    => 'PublicMiddleware',
            'localizationRedirect'  => 'LocalizationMiddleware',
        ],
    ];

    public function boot(Dispatcher $dispatcher, SidebarManager $manager)
    {
        $dispatcher->mapUsing(function ($command) {
            $command = str_replace('Commands\\', 'Commands\\Handlers\\', get_class($command));

            return trim($command, '\\') . 'Handler@handle';
        });

        $manager->register(AdminSidebar::class);

        $this->registerMiddleware($this->app['router']);
        $this->registerModuleResourceNamespaces();
        $this->setLocalesConfigurations();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('asgard.isInstalled', function ($app) {
            try {
                $hasTable = Schema::hasTable('setting__settings');
            } catch (\Exception $e) {
                $hasTable = false;
            }

            return $app['files']->isFile(base_path('.env')) && $hasTable;
        });

        $this->registerCommands();
        $this->registerServices();
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array();
    }

    /**
     * Register the filters.
     *
     * @param  Router $router
     * @return void
     */
    public function registerMiddleware(Router $router)
    {
        foreach ($this->middleware as $module => $middlewares) {
            foreach ($middlewares as $name => $middleware) {
                $class = "Modules\\{$module}\\Http\\Middleware\\{$middleware}";

                $router->middleware($name, $class);
            }
        }
    }

    /**
     * Register the console commands
     */
    private function registerCommands()
    {
        $this->registerInstallCommand();
        $this->registerThemeCommand();
        $this->registerPublishModuleAssetsCommand();

        $this->commands([
            'command.asgard.install',
            'command.asgard.publish.theme',
            'command.asgard.publish.module.assets',
        ]);
    }

    /**
     * Register the installation command
     */
    private function registerInstallCommand()
    {
        $this->app->bind(
            'command.asgard.install',
            'Modules\Core\Console\InstallCommand'
        );
    }

    private function registerThemeCommand()
    {
        $this->app->bindShared('command.asgard.publish.theme', function ($app) {
            return new PublishThemeAssetsCommand(new ThemeManager($app, $app['config']->get('themify.themes_path')));
        });
    }

    private function registerPublishModuleAssetsCommand()
    {
        $this->app->bindShared('command.asgard.publish.module.assets', function () {
            return new PublishModuleAssetsCommand();
        });
    }

    private function registerServices()
    {
        $this->app->bindShared('Modules\Core\Foundation\Theme\ThemeManager', function ($app) {
            $path = $app['config']->get('asgard.core.core.themes_path');

            return new ThemeManager($app, $path);
        });
    }

    /**
     * Register the modules aliases
     */
    private function registerModuleResourceNamespaces()
    {
        foreach ($this->app['modules']->getOrdered() as $module) {
            $this->registerViewNamespace($module);
            $this->registerLanguageNamespace($module);
            $this->registerConfigNamespace($module);
        }
    }

    /**
     * Register the view namespaces for the modules
     * @param Module $module
     */
    protected function registerViewNamespace(Module $module)
    {
        if ($module->getName() == 'user') {
            return;
        }
        $this->app['view']->addNamespace(
            $module->getName(),
            $module->getPath() . '/Resources/views'
        );
    }

    /**
     * Register the language namespaces for the modules
     * @param Module $module
     */
    protected function registerLanguageNamespace(Module $module)
    {
        $moduleName = $module->getName();

        $langPath = base_path("resources/lang/modules/$moduleName");

        if ($this->hasPublishedTranslations($langPath)) {
            $this->loadTranslationsFrom($langPath, $moduleName);
        }

        if ($this->moduleHasCentralisedTranslations($module)) {
            $this->loadTranslationsFrom($this->getCentralisedTranslationPath($module), $moduleName);
        } else {
            $this->loadTranslationsFrom($module->getPath() . '/Resources/lang', $moduleName);
        }
    }

    /**
     * Register the config namespace
     * @param Module $module
     */
    private function registerConfigNamespace(Module $module)
    {
        $files = $this->app['files']->files($module->getPath() . '/Config');

        $package = $module->getName();

        foreach ($files as $file) {
            $filename = $this->getConfigFilename($file, $package);

            $this->mergeConfigFrom(
                $file,
                $filename
            );

            $this->publishes([
                $file => config_path($filename . '.php'),
            ], 'config');
        }
    }

    /**
     * @param $file
     * @param $package
     * @return string
     */
    private function getConfigFilename($file, $package)
    {
        $name = preg_replace('/\\.[^.\\s]{3,4}$/', '', basename($file));

        $filename = $this->prefix . '.' . $package . '.' . $name;

        return $filename;
    }

    /**
     * Set the locale configuration for
     * - laravel localization
     * - laravel translatable
     */
    private function setLocalesConfigurations()
    {
        if (! $this->app['asgard.isInstalled']) {
            return;
        }

        $localeConfig = $this->app['cache']
            ->tags('setting.settings', 'global')
            ->remember("asgard.locales", 120,
                function () {
                    return DB::table('setting__settings')->whereName('core::locales')->first();
                }
            );

        if ($localeConfig) {
            $locales = json_decode($localeConfig->plainValue);
            $availableLocales = [];
            foreach ($locales as $locale) {
                $availableLocales = array_merge($availableLocales, [$locale => config("asgard.core.available-locales.$locale")]);
            }

            $laravelDefaultLocale = $this->app->config->get('app.locale');
            if (! in_array($laravelDefaultLocale, array_keys($availableLocales))) {
                $this->app->config->set('app.locale', array_keys($availableLocales)[0]);
            }
            $this->app->config->set('laravellocalization.supportedLocales', $availableLocales);
            $this->app->config->set('translatable.locales', $locales);
        }
    }

    /**
     * @param string $path
     * @return bool
     */
    private function hasPublishedTranslations($path)
    {
        return is_dir($path);
    }

    /**
     * Does a Module have it's Translations centralised in the Translation module?
     * @param Module $module
     * @return bool
     */
    private function moduleHasCentralisedTranslations(Module $module)
    {
        if (! array_has($this->app['modules']->enabled(), 'Translation')) {
            return false;
        }

        return is_dir($this->getCentralisedTranslationPath($module));
    }

    /**
     * Get the absolute path to the Centralised Translations for a Module (via the Translations module)
     * @param Module $module
     * @return string
     */
    private function getCentralisedTranslationPath(Module $module)
    {
        return $this->app['modules']->find('Translation')->getPath() . "/Resources/lang/{$module->getName()}";
    }
}

