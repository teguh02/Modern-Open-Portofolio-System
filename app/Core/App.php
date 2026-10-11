<?php

declare(strict_types=1);

namespace Mops\Core;

use Mops\Controllers;
use Mops\Middleware\AdminAuthMiddleware;
use Mops\Middleware\CsrfMiddleware;
use Mops\Middleware\InstallMiddleware;
use Mops\Middleware\SessionMiddleware;
use Mops\Middleware\SecurityHeadersMiddleware;
use Mops\Repositories;
use RuntimeException;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

/**
 * Application kernel: builds the container, boots plugins and creates the
 * Slim application with all core middleware.
 */
final class App
{
    public const VERSION = '0.1.0';

    private static ?self $instance = null;

    private Container $container;

    private ?SlimApp $slim = null;

    private bool $pluginsLoaded = false;

    public function __construct(private Config $config)
    {
        self::$instance = $this;
        $this->container = new Container();

        $this->registerServices();
        $this->registerRepositories();
        $this->registerControllers();
    }

    public static function instance(): self
    {
        return self::$instance ?? throw new RuntimeException('MOPS has not been booted yet.');
    }

    public static function isBooted(): bool
    {
        return self::$instance instanceof self;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function get(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function set(string $id, callable $factory): void
    {
        $this->container->set($id, $factory);
    }

    public function db(): Database
    {
        return $this->container->get(Database::class);
    }

    public function settings(): Settings
    {
        return $this->container->get(Settings::class);
    }

    public function isInstalled(): bool
    {
        try {
            return $this->db()->tableExists('users') && $this->db()->table('users')->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Require every active plugin's entry file (runs once per request).
     */
    public function loadPlugins(): void
    {
        if ($this->pluginsLoaded) {
            return;
        }

        $this->pluginsLoaded = true;

        // Plugins must have the hook API available before they are loaded.
        do_action('mops_before_plugins');

        if ($this->isInstalled()) {
            $this->container->get(PluginManager::class)->loadActive();
        }

        do_action('plugins_loaded', $this);

        if ($this->isInstalled()) {
            $this->container->get(ThemeManager::class)->loadActive();
        }

        do_action('after_setup_theme', $this->container->get(ThemeManager::class)->active());
    }

    public function slim(): SlimApp
    {
        if ($this->slim instanceof SlimApp) {
            return $this->slim;
        }

        AppFactory::setContainer($this->container);
        $app = AppFactory::create();

        $this->loadPlugins();

        // Order matters: middleware added later runs earlier (Slim wraps LIFO).
        // Routing is innermost, then security headers, then CSRF — which needs
        // the parsed body, so body parsing is added after CSRF.
        $app->addRoutingMiddleware();
        $app->add(new SecurityHeadersMiddleware($this->config));
        $app->add(new CsrfMiddleware($this->container->get(Csrf::class), $this->config));
        $app->addBodyParsingMiddleware();
        $app->add(new InstallMiddleware($this));
        $app->add(new SessionMiddleware($this->container->get(Session::class)));

        $displayErrors = (bool) $this->config->get('app.debug');
        $errorMiddleware = $app->addErrorMiddleware($displayErrors, true, true);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler(
            $this->container->get(View::class),
            $displayErrors,
        ));

        /** @var array{main: callable, fallback: callable} $routes */
        $routes = require Paths::make('app/routes.php');
        $routes['main']($app, $this);

        // Plugins add their routes here — before the 404 catch-all, which would
        // otherwise shadow them (FastRoute rejects a static route that an
        // earlier variable route already matches).
        do_action('routes_registered', $app, $this);

        $routes['fallback']($app, $this);

        return $this->slim = $app;
    }

    private function registerServices(): void
    {
        $container = $this->container;

        $container->setInstance(Config::class, $this->config);
        $container->setInstance(self::class, $this);

        $container->set(Database::class, fn (): Database => new Database(
            (array) $this->config->get('database'),
        ));

        $container->set(Settings::class, fn (Container $c): Settings => new Settings(
            $c->get(Database::class),
            $this->config,
        ));

        $container->set(Session::class, fn (): Session => new Session(
            (array) $this->config->get('session', []),
        ));

        $container->set(Csrf::class, fn (Container $c): Csrf => new Csrf($c->get(Session::class)));
        $container->set(Auth::class, fn (Container $c): Auth => new Auth(
            $c->get(Database::class),
            $c->get(Session::class),
        ));

        $container->set(SectionRegistry::class, fn (): SectionRegistry => new SectionRegistry());

        $container->bind(Navigation::class);
        $container->bind(Portfolio::class);

        $container->set(ThemeManager::class, fn (Container $c): ThemeManager => new ThemeManager(
            Paths::make('themes'),
            $c->get(Settings::class),
        ));

        $container->set(Uploader::class, fn (): Uploader => new Uploader(
            (array) $this->config->get('uploads', []),
            Paths::ensure(Paths::make($this->config->get('paths.uploads', 'storage/uploads'))),
        ));

        $container->set(PluginManager::class, fn (Container $c): PluginManager => new PluginManager(
            Paths::make('plugins'),
            $c->get(Settings::class),
        ));

        $container->set(Backup::class, fn (Container $c): Backup => new Backup(
            $this->config,
            $c->get(Database::class),
            $c->get(Settings::class),
        ));

        $container->bind(View::class);
        $container->bind(AdminAuthMiddleware::class);

        // Short aliases so plugins can resolve services by convention:
        // mops('settings'), mops('themes'), mops('db'), …
        foreach ([
            'db' => Database::class,
            'settings' => Settings::class,
            'session' => Session::class,
            'csrf' => Csrf::class,
            'auth' => Auth::class,
            'sections' => SectionRegistry::class,
            'themes' => ThemeManager::class,
            'view' => View::class,
            'uploader' => Uploader::class,
            'plugins' => PluginManager::class,
            'backup' => Backup::class,
            'navigation' => Navigation::class,
            'portfolio' => Portfolio::class,
            'app' => self::class,
        ] as $alias => $class) {
            $container->set($alias, fn (Container $c): mixed => $c->get($class));
        }
    }

    private function registerRepositories(): void
    {
        $this->container->bindMany([
            Repositories\ActivityRepository::class,
            Repositories\AwardRepository::class,
            Repositories\CertificationRepository::class,
            Repositories\EducationRepository::class,
            Repositories\ExperienceRepository::class,
            Repositories\MediaRepository::class,
            Repositories\PostRepository::class,
            Repositories\ProfileRepository::class,
            Repositories\ProjectRepository::class,
            Repositories\SkillRepository::class,
            Repositories\UserRepository::class,
        ]);
    }

    private function registerControllers(): void
    {
        $this->container->bindMany([
            Controllers\AuthController::class,
            Controllers\BlogController::class,
            Controllers\HomeController::class,
            Controllers\InstallController::class,
            Controllers\SectionController::class,
            Controllers\AssetController::class,

            Controllers\Admin\AdminController::class,
            Controllers\Admin\BackupController::class,
            Controllers\Admin\CertificationController::class,
            Controllers\Admin\DashboardController::class,
            Controllers\Admin\EducationController::class,
            Controllers\Admin\ExperienceController::class,
            Controllers\Admin\AwardController::class,
            Controllers\Admin\MediaController::class,
            Controllers\Admin\PluginController::class,
            Controllers\Admin\PostController::class,
            Controllers\Admin\ProfileController::class,
            Controllers\Admin\ProjectController::class,
            Controllers\Admin\SettingsController::class,
            Controllers\Admin\SkillController::class,
            Controllers\Admin\ThemeController::class,
        ]);
    }
}
