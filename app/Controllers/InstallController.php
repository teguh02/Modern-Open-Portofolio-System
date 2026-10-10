<?php

declare(strict_types=1);

namespace Mops\Controllers;

use Mops\Core\App;
use Mops\Core\Auth;
use Mops\Core\Config;
use Mops\Core\Database;
use Mops\Core\Migrator;
use Mops\Core\Paths;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\Str;
use Mops\Core\Validator;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Mops\Repositories\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Installer: requirement checks, database migration, admin account and site
 * settings — all in one screen so a fresh install never lands in a half state.
 *
 * No seeding happens here: the Section Registry defaults live in code, so a
 * fresh install shows only identity + bio until an admin toggles more.
 */
final class InstallController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private Config $config,
        private Database $db,
        private Settings $settings,
        private UserRepository $users,
        private ActivityRepository $activity,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->kernel()->isInstalled()) {
            return $this->redirect($response, '/admin');
        }

        return $this->view($response, 'install/index.twig', [
            'requirements' => $this->checks(),
            'values' => ['site_name' => 'MOPS', 'locale' => 'id'],
            'errors' => [],
            'can_install' => $this->canInstall(),
        ]);
    }

    public function requirements(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['requirements' => $this->checks()]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->kernel()->isInstalled()) {
            return $this->redirect($response, '/admin');
        }

        $input = (array) ($request->getParsedBody() ?? []);

        $validator = Validator::make($input, [
            'site_name' => 'required|max:120',
            'name' => 'required|max:120',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
            'locale' => 'nullable|in:id,en',
        ], [
            'site_name' => 'Nama situs',
            'name' => 'Nama admin',
            'email' => 'Email',
            'password' => 'Password',
        ]);

        if ($validator->fails() || !$this->canInstall()) {
            $errors = $validator->errorList();

            if (!$this->canInstall()) {
                $errors[] = 'Persyaratan sistem belum terpenuhi.';
            }

            return $this->view($response, 'install/index.twig', [
                'requirements' => $this->checks(),
                'values' => array_merge(['site_name' => 'MOPS', 'locale' => 'id'], $input),
                'errors' => $errors,
                'can_install' => $this->canInstall(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            // Migrations each run in their own transaction, so they are not
            // wrapped again here (PDO has no nested transactions).
            $migrator = new Migrator($this->db, $this->config->path('migrations'));
            $migrator->run();

            $this->users->create([
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'password' => (string) $data['password'],
                'role' => 'admin',
            ]);
        } catch (Throwable $e) {
            $this->flash('error', 'Instalasi gagal: ' . $e->getMessage());

            return $this->view($response, 'install/index.twig', [
                'requirements' => $this->checks(),
                'values' => array_merge(['site_name' => 'MOPS', 'locale' => 'id'], $input),
                'errors' => ['Instalasi gagal: ' . $e->getMessage()],
                'can_install' => $this->canInstall(),
            ], 500);
        }

        $this->settings->setMany([
            'site_name' => (string) $data['site_name'],
            'site_tagline' => 'Portfolio',
            'locale' => (string) ($data['locale'] ?? 'id'),
            'installed_at' => date('Y-m-d H:i:s'),
            // Both bundled themes ship; vantablo is the fresh-install default and
            // the admin can switch freely from /admin/themes.
            'active_theme' => 'vantablo',
        ]);

        $this->activity->log('install', 'MOPS diinstal', null, 'app');

        $this->flash('success', 'Instalasi selesai. Silakan masuk dengan akun admin Anda.');

        return $this->redirect($response, '/admin/login');
    }

    /**
     * @return array<int, array{label: string, ok: bool, detail: string, required: bool}>
     */
    private function checks(): array
    {
        $storage = Paths::make('storage');

        return [
            [
                'label' => 'PHP >= 8.2',
                'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'detail' => 'Terpasang: ' . PHP_VERSION,
                'required' => true,
            ],
            [
                'label' => 'Ekstensi PDO + driver',
                'ok' => extension_loaded('pdo') && (
                    ($this->db->driver() === 'sqlite' && extension_loaded('pdo_sqlite'))
                    || ($this->db->driver() === 'mysql' && extension_loaded('pdo_mysql'))
                    || ($this->db->driver() === 'pgsql' && extension_loaded('pdo_pgsql'))
                ),
                'detail' => 'Driver: ' . $this->db->driver(),
                'required' => true,
            ],
            [
                'label' => 'Ekstensi mbstring',
                'ok' => extension_loaded('mbstring'),
                'detail' => 'Untuk pemotongan teks multibyte',
                'required' => true,
            ],
            [
                'label' => 'Ekstensi fileinfo',
                'ok' => extension_loaded('fileinfo'),
                'detail' => 'Validasi MIME upload',
                'required' => true,
            ],
            [
                'label' => 'storage/ dapat ditulis',
                'ok' => is_dir($storage) && is_writable($storage),
                'detail' => Str::limit($storage, 60),
                'required' => true,
            ],
            [
                'label' => 'Ekstensi zip',
                'ok' => class_exists(\ZipArchive::class),
                'detail' => class_exists(\ZipArchive::class) ? 'Backup penuh tersedia' : 'Backup database saja',
                'required' => false,
            ],
            [
                'label' => 'Ekstensi gd',
                'ok' => extension_loaded('gd'),
                'detail' => extension_loaded('gd') ? 'Siap' : 'Opsional, untuk resize gambar di masa depan',
                'required' => false,
            ],
        ];
    }

    private function canInstall(): bool
    {
        foreach ($this->checks() as $requirement) {
            if ($requirement['required'] && !$requirement['ok']) {
                return false;
            }
        }

        return true;
    }

    private function kernel(): App
    {
        return App::instance();
    }
}
