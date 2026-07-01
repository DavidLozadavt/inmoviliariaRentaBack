<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

trait HasSmartScopes
{
    /**
     * Columnas que nunca deben filtrarse.
     */
    private static array $blockedFilterColumns = [
        'password', 'password_hash', 'remember_token', 'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    public function scopeIncluded(Builder $query)
    {
        $included = request('included');
        if (!$included) return;

        $relations = explode(',', $included);
        $valid = [];

        foreach ($relations as $relationPath) {
            if ($this->isValidNestedRelation($this, explode('.', $relationPath))) {
                $valid[] = $relationPath;
            }
        }

        if (!empty($valid)) {
            $query->with($valid);
        }
    }

    public function scopeFilter(Builder $query)
    {
        $filters = request('filter');
        if (!$filters) return;

        $columns = $this->getTableColumns();

        foreach ($filters as $column => $value) {
            // Bloquear columnas sensibles
            if (in_array($column, self::$blockedFilterColumns, true)) {
                continue;
            }

            if (in_array($column, $columns, true)) {
                $query->where($column, 'LIKE', "%{$value}%");
            }
        }
    }

    public function scopeSort(Builder $query)
    {
        $sort = request('sort');
        if (!$sort) return;

        $columns = Schema::getColumnListing($this->getTable());

        foreach (explode(',', $sort) as $field) {
            $direction = str_starts_with($field, '-') ? 'desc' : 'asc';
            $column = ltrim($field, '-');

            // Bloquear sort por columnas sensibles
            if (in_array($column, self::$blockedFilterColumns, true)) {
                continue;
            }

            if (in_array($column, $columns, true)) {
                $query->orderBy($column, $direction);
            }
        }
    }

    public function scopeGetOrPaginate(Builder $query)
    {
        $perPage = intval(request('perPage'));

        return $perPage > 0
            ? $query->paginate($perPage)->appends(request()->query())
            : $query->get();
    }

    protected function isValidNestedRelation(Model $model, array $segments): bool
    {
        $current = array_shift($segments);
        $method = Str::camel($current);

        if (!method_exists($model, $method)) return false;

        $reflection = new ReflectionMethod($model, $method);

        if ($reflection->getNumberOfParameters() > 0) return false;

        $return = $reflection->invoke($model);

        if (!$return instanceof Relation) return false;

        return empty($segments)
            ? true
            : $this->isValidNestedRelation($return->getRelated(), $segments);
    }

    protected function getTableColumns(): array
    {
        return Schema::getColumnListing($this->getTable());
    }
}