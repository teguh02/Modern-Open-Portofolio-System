<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\Auth;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\Uploader;
use Mops\Core\Validator;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Mops\Repositories\MediaRepository;
use Mops\Repositories\Repository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Generic CRUD for every portfolio resource. Concrete controllers only declare
 * a field schema — the list table, the form (all field types), validation,
 * search, sorting, pagination and bulk actions are shared.
 *
 * Admin cannot change section order or item limits: those live in the Section
 * Registry (visibility toggles live in Settings).
 */
abstract class ResourceController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
        protected Uploader $uploader,
        protected MediaRepository $media,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry);
    }

    /**
     * URL segment, e.g. 'experiences'.
     */
    abstract protected function routeBase(): string;

    /**
     * Page + menu title, e.g. 'Riwayat Kerja'.
     */
    abstract protected function title(): string;

    /**
     * Lowercase label used in messages, e.g. 'riwayat kerja'.
     */
    abstract protected function singular(): string;

    abstract protected function repository(): Repository;

    /**
     * Form schema. Field shape:
     *   name, label, type, col, required, hint, options, rules
     *
     * Types: text, textarea, richtext, list, select, multiselect, date, number,
     * email, url, toggle, range, media, hidden.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract protected function fields(): array;

    /**
     * Table columns. Shape: name, label, type (text, date, badge, boolean, image,
     * link), sortable.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function columns(): array
    {
        return [
            ['name' => 'title', 'label' => 'Title', 'sortable' => true],
            ['name' => 'created_at', 'label' => 'Created', 'type' => 'date', 'sortable' => true],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return ['title'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    protected function perPage(): int
    {
        return 10;
    }

    /**
     * Show a status/draft quick toggle in the table.
     */
    protected function canToggle(): bool
    {
        return false;
    }

    protected function toggleField(): string
    {
        return 'status';
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function toggleValues(): array
    {
        return ['draft', 'published'];
    }

    /**
     * Hook for turning validated input into row data (JSON encode, slug, ...).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    protected function prepare(array $data, ?array $existing = null): array
    {
        return $data;
    }

    // ------------------------------------------------------------------ actions

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = trim((string) ($query['q'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $repository = $this->repository();
        $total = $repository->count();

        /** @var array<int, array<string, mixed>> $items */
        $items = $repository->all();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter(
                $items,
                function (array $row) use ($needle): bool {
                    foreach ($this->searchable() as $column) {
                        $value = mb_strtolower((string) ($row[$column] ?? ''));

                        if ($value !== '' && str_contains($value, $needle)) {
                            return true;
                        }
                    }

                    return false;
                },
            ));
            $total = count($items);
        }

        $items = $this->sortItems($items, $query);
        [$sort, $dir] = $this->resolvedSort($query);

        $perPage = $this->perPage();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $items = array_slice($items, ($page - 1) * $perPage, $perPage);

        return $this->admin($response, 'resources/index.twig', [
            'title' => $this->title(),
            'crumbs' => $this->crumbs($this->title()),
            'route_base' => $this->routeBase(),
            'resource_title' => $this->title(),
            'singular' => $this->singular(),
            'columns' => $this->columns(),
            'items' => $items,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'total' => $total,
                'per_page' => $perPage,
            ],
            'can_toggle' => $this->canToggle(),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->form($response, null);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);

        return $this->persist($response, $input, null);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $row = $this->repository()->find((int) ($args['id'] ?? 0));

        if ($row === null) {
            return $this->missing($response);
        }

        return $this->form($response, $row);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $row = $this->repository()->find($id);

        if ($row === null) {
            return $this->missing($response);
        }

        $input = (array) ($request->getParsedBody() ?? []);

        return $this->persist($response, $input, $row);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $row = $this->repository()->find($id);

        if ($row === null) {
            return $this->missing($response);
        }

        $this->repository()->delete($id);
        $this->log('delete', $this->describe($row));

        return $this->redirect($response, "/admin/{$this->routeBase()}");
    }

    public function bulk(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);
        $ids = array_values(array_filter(array_map('intval', (array) ($input['ids'] ?? []))));
        $action = (string) ($input['action'] ?? '');

        if ($ids === []) {
            $this->flash('error', 'Tidak ada item yang dipilih.');

            return $this->redirect($response, "/admin/{$this->routeBase()}");
        }

        if ($action === 'delete') {
            $this->repository()->deleteMany($ids);
            $this->log('bulk_delete', count($ids) . ' ' . $this->title() . ' dihapus');
            $this->flash('success', count($ids) . ' item dihapus.');
        }

        return $this->redirect($response, "/admin/{$this->routeBase()}");
    }

    public function toggle(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->canToggle()) {
            return $this->missing($response);
        }

        $id = (int) ($args['id'] ?? 0);
        $row = $this->repository()->find($id);

        if ($row === null) {
            return $this->missing($response);
        }

        [$draft, $published] = $this->toggleValues();
        $field = $this->toggleField();
        $current = (string) ($row[$field] ?? $draft);
        $next = $current === $published ? $draft : $published;

        $this->repository()->update($id, [$field => $next]);
        $this->log('toggle', $this->describe($row) . " -> {$next}");
        $this->flash('success', "Status diperbarui: {$next}.");

        return $this->redirect($response, "/admin/{$this->routeBase()}");
    }

    // ------------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed>|null $existing
     */
    protected function form(ResponseInterface $response, ?array $existing): ResponseInterface
    {
        $values = [];

        foreach ($this->fields() as $field) {
            $name = (string) $field['name'];
            $values[$name] = $existing[$name] ?? null;

            // JSON list fields round-trip through a textarea (one item per line).
            if (($field['type'] ?? '') === 'list' && is_array($values[$name])) {
                $values[$name] = implode("\n", array_map(static fn (mixed $item): string => (string) $item, $values[$name]));
            }
        }

        return $this->admin($response, 'resources/form.twig', [
            'title' => ($existing === null ? 'New ' : 'Edit ') . $this->title(),
            'crumbs' => $this->crumbs($existing === null ? 'New' : 'Edit', $this->title(), "/admin/{$this->routeBase()}"),
            'route_base' => $this->routeBase(),
            'resource_title' => $this->title(),
            'singular' => $this->singular(),
            'fields' => $this->fields(),
            'values' => $values,
            'errors' => [],
            'record' => $existing,
            'action' => $existing === null
                ? "/admin/{$this->routeBase()}"
                : "/admin/{$this->routeBase()}/{$existing['id']}",
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    protected function persist(ResponseInterface $response, array $input, ?array $existing): ResponseInterface
    {
        $validator = Validator::make($input, $this->rules(), $this->labels());

        if ($validator->fails()) {
            $values = array_merge($this->fieldDefaults($existing), $input);

            return $this->admin($response, 'resources/form.twig', [
                'title' => ($existing === null ? 'New ' : 'Edit ') . $this->title(),
                'crumbs' => $this->crumbs($existing === null ? 'New' : 'Edit', $this->title(), "/admin/{$this->routeBase()}"),
                'route_base' => $this->routeBase(),
                'resource_title' => $this->title(),
                'singular' => $this->singular(),
                'fields' => $this->fields(),
                'values' => $values,
                'errors' => $validator->errors(),
                'error_list' => $validator->errorList(),
                'record' => $existing,
                'action' => $existing === null
                    ? "/admin/{$this->routeBase()}"
                    : "/admin/{$this->routeBase()}/{$existing['id']}",
            ], 422);
        }

        $data = $validator->validated();

        try {
            $data = $this->applyUploads($data);
            $data = $this->applyDefaults($data);
            $data = $this->normalizeListFields($data);
            $data = $this->prepare($data, $existing);

            if ($existing === null) {
                $row = $this->repository()->create($data);
                $this->log('create', $this->describe($row));
                $this->flash('success', ucfirst($this->singular()) . ' berhasil ditambahkan.');
            } else {
                $this->repository()->update((int) $existing['id'], $data);
                $this->log('update', $this->describe($existing));
                $this->flash('success', ucfirst($this->singular()) . ' berhasil diperbarui.');
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Gagal menyimpan: ' . $e->getMessage());

            return $this->admin($response, 'resources/form.twig', [
                'title' => ($existing === null ? 'New ' : 'Edit ') . $this->title(),
                'crumbs' => $this->crumbs($existing === null ? 'New' : 'Edit', $this->title(), "/admin/{$this->routeBase()}"),
                'route_base' => $this->routeBase(),
                'resource_title' => $this->title(),
                'singular' => $this->singular(),
                'fields' => $this->fields(),
                'values' => array_merge($this->fieldDefaults($existing), $input),
                'errors' => [],
                'error_list' => [$e->getMessage()],
                'record' => $existing,
                'action' => "/admin/{$this->routeBase()}",
            ], 500);
        }

        return $this->redirect($response, "/admin/{$this->routeBase()}");
    }

    /**
     * Field-driven validation rules.
     *
     * @return array<string, string>
     */
    protected function rules(): array
    {
        $rules = [];

        foreach ($this->fields() as $field) {
            $name = (string) $field['name'];
            $type = (string) ($field['type'] ?? 'text');
            $parts = [];

            if (($field['required'] ?? false) === true) {
                $parts[] = 'required';
            } else {
                $parts[] = 'nullable';
            }

            $parts[] = match ($type) {
                'email' => 'email',
                'url' => 'url',
                'date', 'datetime' => 'date',
                'number' => 'numeric',
                'toggle' => 'boolean',
                'select' => 'in:' . implode(',', array_keys((array) ($field['options'] ?? []))),
                'media' => 'max:255',
                default => 'max:' . (int) ($field['max'] ?? 5000),
            };

            if (isset($field['rules'])) {
                $parts[] = (string) $field['rules'];
            }

            $rules[$name] = implode('|', $parts);
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function labels(): array
    {
        $labels = [];

        foreach ($this->fields() as $field) {
            $labels[(string) $field['name']] = (string) ($field['label'] ?? $field['name']);
        }

        return $labels;
    }

    /**
     * A blank optional field falls back to the default declared in the field
     * schema (sort order 0, status draft, …) instead of NULL, which keeps
     * NOT NULL columns with defaults happy.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function applyDefaults(array $data): array
    {
        foreach ($this->fields() as $field) {
            $name = (string) $field['name'];

            if (!array_key_exists('default', $field)) {
                continue;
            }

            if (($data[$name] ?? null) === null || ($data[$name] ?? '') === '') {
                $data[$name] = $field['default'];
            }
        }

        return $data;
    }

    /**
     * `list` fields are edited as one value per line; repositories persist them
     * as JSON text in a portable column.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function normalizeListFields(array $data): array
    {
        foreach ($this->fields() as $field) {
            if (($field['type'] ?? '') !== 'list') {
                continue;
            }

            $name = (string) $field['name'];
            $value = $data[$name] ?? null;

            if (is_string($value)) {
                $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
            }

            if (!is_array($value)) {
                $data[$name] = [];
                continue;
            }

            $data[$name] = array_values(array_filter(
                array_map(static fn (mixed $line): string => trim((string) $line), $value),
                static fn (string $line): bool => $line !== '',
            ));
        }

        return $data;
    }

    /**
     * Turn uploaded files attached to `media` fields into stored paths.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function applyUploads(array $data): array
    {
        $files = $_FILES ?? [];

        foreach ($this->fields() as $field) {
            if (($field['type'] ?? '') !== 'media') {
                continue;
            }

            $name = (string) $field['name'];
            $key = $name . '_upload';

            if (!isset($files[$key]) || ($files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $stored = $this->uploader->store($files[$key]);

            if ($stored === null) {
                $errors = implode(' ', $this->uploader->errors());

                throw new \RuntimeException($errors !== '' ? $errors : 'Upload gagal.');
            }

            $media = $this->media->record($stored, [
                'alt' => (string) ($field['label'] ?? $name),
                'uploaded_by' => $this->auth->id(),
            ]);

            $data[$name] = (string) $media['path'];
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function fieldDefaults(?array $existing): array
    {
        $values = [];

        foreach ($this->fields() as $field) {
            $name = (string) $field['name'];
            $values[$name] = $existing[$name] ?? ($field['default'] ?? null);
        }

        return $values;
    }

    /**
     * Requested sort, falling back to the resource default for unknown columns.
     *
     * @param array<string, mixed> $query
     * @return array{0: string, 1: string}
     */
    private function resolvedSort(array $query): array
    {
        $columns = array_map(static fn (array $column): string => (string) $column['name'], $this->columns());
        $sort = (string) ($query['sort'] ?? $this->defaultSort()[0]);
        $direction = strtolower((string) ($query['dir'] ?? $this->defaultSort()[1]));

        if (!in_array($sort, $columns, true)) {
            [$sort, $direction] = $this->defaultSort();
        }

        return [$sort, $direction === 'asc' ? 'asc' : 'desc'];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    private function sortItems(array $items, array $query): array
    {
        [$sort, $direction] = $this->resolvedSort($query);

        usort($items, static function (array $a, array $b) use ($sort, $direction): int {
            $left = $a[$sort] ?? null;
            $right = $b[$sort] ?? null;

            if (is_bool($left) || is_int($left) || is_bool($right) || is_int($right)) {
                $compare = ((int) $left) <=> ((int) $right);
            } else {
                $compare = strcmp((string) $left, (string) $right);
            }

            return $direction === 'asc' ? $compare : -$compare;
        });

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function describe(array $row): string
    {
        foreach (['title', 'name', 'full_name', 'school', 'company'] as $key) {
            if (!empty($row[$key])) {
                return (string) $row[$key];
            }
        }

        return '#' . ($row['id'] ?? '?');
    }

    protected function log(string $action, string $description): void
    {
        $this->activity->log($action, $description, $this->auth->id(), $this->routeBase(), null);
    }

    protected function missing(ResponseInterface $response): ResponseInterface
    {
        $this->flash('error', 'Data tidak ditemukan.');

        return $this->redirect($response, "/admin/{$this->routeBase()}");
    }
}
