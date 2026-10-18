<?php

namespace App\Services\Admin;

use App\Filament\Pages\DatabaseBrowser as BrowserPage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DatabaseBrowser
{
    private array $columnCache = [];

    private ?array $tableCache = null;

    private ?array $resourceCache = null;

    private array $foreignKeyCache = [];

    private ?object $authorizedRequest = null;

    private int|string|null $authorizedUser = null;

    public function __construct(private DatabaseValueRedactor $redactor) {}

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $user = $user instanceof User ? $user->fresh() : null;

        return $user !== null && $user->canAccessPanel(Filament::getPanel('admin'));
    }

    public function authorize(): void
    {
        // This service is owned by one page request, never a singleton. Recheck
        // on every new Livewire request, without querying the user per table cell.
        if ($this->authorizedRequest === request() && $this->authorizedUser === Filament::auth()->id()) {
            return;
        }
        abort_unless(self::canAccess(), 403);
        $this->authorizedRequest = request();
        $this->authorizedUser = Filament::auth()->id();
    }

    public function tables(): array
    {
        $this->authorize();

        return $this->tableCache ??= array_values(array_filter(Schema::getTableListing(schemaQualified: false), fn (string $name): bool => preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name) && ! str_starts_with($name, 'sqlite_')));
    }

    public function columns(string $table): array
    {
        $this->authorize();
        abort_unless(in_array($table, $this->tables(), true), 404);

        return $this->columnCache[$table] ??= array_column(Schema::getColumns($table), null, 'name');
    }

    public function listColumns(string $table): array
    {
        $columns = $this->columns($table);
        $preferred = ['id', 'key', 'title', 'name', 'label', 'model', 'status', 'domain', 'phase', 'action', 'role', 'queue',
            'product_draft_id', 'telegram_update_id', 'product_id', 'product_variant_id', 'captured_via', 'url',
            'specification_text', 'created_at', 'updated_at'];
        $picked = array_values(array_intersect($preferred, array_keys($columns)));
        if (count($picked) < 4) {
            $picked = array_values(array_unique([...$picked, ...array_slice(array_keys($columns), 0, 6)]));
        }

        return array_values(array_filter($picked, fn (string $name): bool => ! $this->redactor->hiddenColumn($table, $name)));
    }

    public function resources(): array
    {
        $this->authorize();
        if ($this->resourceCache !== null) {
            return $this->resourceCache;
        }
        $result = [];
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $result[(new ($resource::getModel()))->getTable()] = $resource;
        }

        return $this->resourceCache = $result;
    }

    public function tableOptions(): array
    {
        $resources = $this->resources();
        $result = [];
        foreach ($this->tables() as $table) {
            $result[$table] = $table.(isset($resources[$table]) ? ' — '.$resources[$table]::getPluralModelLabel() : ' — '.__('Обзор БД'));
        }
        ksort($result);

        return $result;
    }

    private function identityColumns(string $table): array
    {
        $indexes = Schema::getIndexes($table);
        foreach ([true, false] as $primary) {
            foreach ($indexes as $index) {
                if (($primary ? $index['primary'] : $index['unique']) && $index['columns'] !== []) {
                    return $index['columns'];
                }
            }
        }

        // A link table without a declared unique key still has an exact row locator.
        return array_keys($this->columns($table));
    }

    public function records(string $table, ?string $search = null, ?string $sort = null, ?string $direction = null,
        int|string $perPage = 'all', int $page = 1, array $filter = []): Collection|LengthAwarePaginator
    {
        $columns = $this->columns($table);
        $visible = $this->listColumns($table);
        $identity = $this->identityColumns($table);
        $query = DB::table($table);
        $filterColumn = $filter['column'] ?? null;
        if (filled($filterColumn)) {
            abort_unless(is_string($filterColumn) && in_array($filterColumn, $visible, true), 422);
            $query->where($filterColumn, '=', (string) ($filter['value'] ?? ''));
        }
        if (filled($search)) {
            // Hidden values are not searchable, preventing an equality/prefix oracle.
            $query->where(function ($query) use ($visible, $search): void {
                foreach ($visible as $name) {
                    $query->orWhereLike($name, '%'.$search.'%');
                }
            });
        }
        if ($sort !== null) {
            abort_unless(in_array($sort, $visible, true) && in_array($direction, ['asc', 'desc'], true), 422);
        }
        $sort ??= in_array('id', $visible, true) ? 'id' : ($visible[0] ?? $identity[0]);
        $query->orderBy($sort, $direction ?? 'desc');
        $total = (clone $query)->count();
        abort_unless(in_array($perPage, ['all', 25, 50, 100, '25', '50', '100'], true), 422);
        if ($perPage !== 'all') {
            $query->forPage(max(1, $page), (int) $perPage);
        }
        // Large JSON/DOM bodies are fetched only when one record is opened.
        $query->select($identity);
        $grammar = DB::connection()->getQueryGrammar();
        foreach (array_diff($visible, $identity) as $column) {
            $wrapped = $grammar->wrap($column);
            $query->selectRaw("substr($wrapped, 1, 600) as $wrapped");
        }
        $rows = $query->get()->mapWithKeys(function (object $record) use ($table, $visible, $identity): array {
            $row = (array) $record;
            $where = array_intersect_key($row, array_flip($identity));
            $key = hash_hmac('sha256', $table.json_encode($where), (string) config('app.key'));
            $safe = ['_locator' => Crypt::encryptString(json_encode(['table' => $table, 'where' => $where]))];
            foreach ($visible as $name) {
                $safe[$name] = mb_substr($this->redactor->text($row[$name] ?? null), 0, 240);
            }

            return [$key => $safe];
        });

        return $perPage === 'all' ? $rows : new LengthAwarePaginator($rows, $total, (int) $perPage, max(1, $page));
    }

    public function record(string $table, string $locator): array
    {
        $columns = $this->columns($table);
        try {
            $decoded = json_decode(Crypt::decryptString($locator), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(404);
        }
        abort_unless(($decoded['table'] ?? null) === $table && is_array($decoded['where'] ?? null)
            && array_keys($decoded['where']) === $this->identityColumns($table), 404);
        $query = DB::table($table);
        foreach ($decoded['where'] as $key => $value) {
            abort_unless(isset($columns[$key]) && (is_scalar($value) || $value === null), 404);
            $query->where($key, $value);
        }
        $public = array_filter(array_keys($columns), fn (string $key): bool => ! $this->redactor->hiddenColumn($table, $key));
        $raw = $query->first($public ?: [DB::raw('1 as _exists')]);
        abort_unless($raw !== null, 404);
        $result = [];
        foreach ($columns as $name => $column) {
            $result[$name] = $this->redactor->hiddenColumn($table, $name)
                ? DatabaseValueRedactor::HIDDEN : $this->redactor->text($raw->{$name} ?? null);
        }

        return $result;
    }

    public function resourceUrl(string $table, mixed $id = null): ?string
    {
        $resource = $this->resources()[$table] ?? null;
        if ($resource === null) {
            return null;
        }
        $pages = $resource::getPages();
        $page = filled($id) ? (isset($pages['view']) ? 'view' : (isset($pages['edit']) ? 'edit' : 'index')) : 'index';

        return $resource::getUrl($page, $page === 'index' ? [] : ['record' => $id]);
    }

    public function relatedUrl(string $table, string $column, string $value): ?string
    {
        $this->columns($table);
        if ($value === '—' || $value === DatabaseValueRedactor::HIDDEN) {
            return null;
        }
        foreach ($this->foreignKeyCache[$table] ??= Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column] && count($key['foreign_columns']) === 1) {
                $foreign = $key['foreign_table'];

                return $this->resourceUrl($foreign, $value) ?? BrowserPage::getUrl([
                    'table' => $foreign, 'filters' => ['field' => ['column' => $key['foreign_columns'][0], 'value' => $value]],
                ]);
            }
        }

        return null;
    }
}
