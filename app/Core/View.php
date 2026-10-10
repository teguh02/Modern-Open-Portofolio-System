<?php

declare(strict_types=1);

namespace Mops\Core;

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig wiring. Templates resolve in this order:
 *
 *   1. themes/<active>/templates   (public pages — the theme owns the markup)
 *   2. app/Views                   (admin + core templates)
 *   3. app/Views/fallback          (so a theme missing a partial still renders)
 *
 * Themes never receive table or column names — controllers hand them a
 * prepared `portfolio` / `sections` payload.
 */
final class View
{
    private ?Environment $twig = null;

    public function __construct(
        private Config $config,
        private ThemeManager $themes,
        private Settings $settings,
        private Session $session,
        private Csrf $csrf,
        private Auth $auth,
        private SectionRegistry $registry,
        private Navigation $navigation,
    ) {
    }

    public function environment(): Environment
    {
        if ($this->twig instanceof Environment) {
            return $this->twig;
        }

        // Template lookup order: active theme, app views, core fallback, then any
        // extra paths a plugin registered through the `view_paths` filter.
        $paths = Hook::applyFilters('view_paths', [
            $this->themes->templatePath(),
            Paths::make('app/Views'),
            Paths::make('app/Views/fallback'),
        ]);

        $loader = new ChainLoader(array_map(
            static fn (string $path): FilesystemLoader => new FilesystemLoader($path),
            array_values(array_filter($paths, 'is_dir')),
        ));

        $options = [
            'cache' => $this->config->get('app.debug') === true
                ? false
                : Paths::ensure($this->config->path('cache')) . '/twig',
            'debug' => $this->config->get('app.debug') === true,
            'autoescape' => 'html',
            'strict_variables' => false,
            'auto_reload' => true,
        ];

        $twig = new Environment($loader, $options);
        $twig->addExtension(new \Twig\Extension\DebugExtension());

        $this->registerFunctions($twig);
        $this->registerGlobals($twig);

        return $this->twig = $twig;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = []): string
    {
        return $this->environment()->render($template, $this->requestContext($context));
    }

    /**
     * Twig globals are frozen when the environment is built, so a long-lived
     * kernel (CLI, tests) would keep the first request's path. Context values win
     * over globals, so the path zones and menus read is always the current one.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function requestContext(array $context): array
    {
        return $context + ['current_path' => $this->currentPath()];
    }

    /**
     * Render a public template, falling back to a generic core notice so a theme
     * that ships an incomplete template set never takes the site down.
     *
     * @param array<string, mixed> $context
     */
    public function renderPublic(string $template, array $context = []): string
    {
        try {
            return $this->render($template, $context);
        } catch (LoaderError) {
            return $this->render('fallback/missing.twig', ['missing_template' => $template] + $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function renderSafe(string $template, array $context = []): ?string
    {
        try {
            return $this->render($template, $context);
        } catch (LoaderError) {
            return null;
        }
    }

    public function exists(string $template): bool
    {
        return $this->environment()->getLoader()->exists($template);
    }

    /**
     * Drop the built environment so the next render picks up changed template
     * locations — the active theme, or a plugin that just registered its views.
     * The backend is rebuilt from the current filters, so callers only need to
     * refresh after switching themes or (de)activating plugins.
     */
    public function refresh(): void
    {
        $this->twig = null;
    }

    private function registerFunctions(Environment $twig): void
    {
        $twig->addFunction(new TwigFunction('asset', 'asset'));
        $twig->addFunction(new TwigFunction('theme_asset', 'theme_asset'));
        $twig->addFunction(new TwigFunction('setting', 'setting'));
        $twig->addFunction(new TwigFunction('upload_url', 'upload_url'));
        $twig->addFunction(new TwigFunction('csrf_field', fn (): string => $this->csrf->field(), ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', fn (): string => $this->csrf->token()));
        $twig->addFunction(new TwigFunction('do_action', 'do_action'));

        // Render a hook and inline its output — the admin dashboard uses this so
        // plugins can add cards without core knowing about them.
        $twig->addFunction(new TwigFunction('render_hook', static function (string $hook, mixed ...$args): string {
            ob_start();

            try {
                Hook::doAction($hook, ...$args);
            } catch (\Throwable $e) {
                ob_end_clean();

                throw $e;
            }

            return (string) ob_get_clean();
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('apply_filters', 'apply_filters'));
        $twig->addFunction(new TwigFunction('has_action', [Hook::class, 'hasAction']));
        $twig->addFunction(new TwigFunction('section_meta', fn (string $id): ?array => $this->registry->find($id)));
        $twig->addFunction(new TwigFunction('html', fn (string $value): string => $value, ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('safe', fn (string $value): string => $value, ['is_safe' => ['html']]));

        $twig->addFilter(new TwigFilter('slug', [Str::class, 'slug']));
        $twig->addFilter(new TwigFilter('excerpt', [Str::class, 'excerpt']));
        $twig->addFilter(new TwigFilter('reading_time', [Str::class, 'readingTime']));
        $twig->addFilter(new TwigFilter('t_date', [Str::class, 'date']));
        $twig->addFilter(new TwigFilter('date_range', [Str::class, 'dateRange']));
        $twig->addFilter(new TwigFilter('clean_html', [Str::class, 'sanitizeHtml'], ['is_safe' => ['html']]));
    }

    private function registerGlobals(Environment $twig): void
    {
        $twig->addGlobal('app', [
            'name' => $this->config->get('app.name'),
            'env' => $this->config->get('app.env'),
            'debug' => $this->config->get('app.debug'),
            'url' => $this->config->get('app.url'),
        ]);

        $twig->addGlobal('site', [
            'name' => $this->settings->get('site_name', 'MOPS'),
            'tagline' => $this->settings->get('site_tagline', ''),
            'description' => $this->settings->get('site_description', ''),
            'locale' => $this->settings->get('locale', 'id'),
        ]);

        $twig->addGlobal('auth_user', $this->auth->user());
        $twig->addGlobal('csrf_token', $this->csrf->token());
        $twig->addGlobal('flashes', $this->session->flashes());
        $twig->addGlobal('old_input', $this->session->oldInput());
        $twig->addGlobal('current_path', $this->currentPath());
        $twig->addGlobal('theme', $this->themes->active());
        // Core-computed public navigation: enabled sections that have data.
        $twig->addGlobal('nav', $this->navigation->items());
    }

    private function currentPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
