<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;
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
    public function boot(): void
    {
        // Guard against 1071 "Specified key was too long" on older MySQL/MariaDB utf8mb4 schemas.
        Schema::defaultStringLength(191);

        $registerLikeMacro = function (string $method, string $boolean): void {
            $handler = function (string $column, string $value) use ($boolean) {
                $query = $this instanceof EloquentBuilder ? $this->getQuery() : $this;
                $driver = $query->getConnection()->getDriverName();

                if ($driver === 'pgsql') {
                    return $this->where($column, 'ilike', $value, $boolean);
                }

                $qualified = $this instanceof EloquentBuilder ? $this->qualifyColumn($column) : $column;
                $wrapped = $query->getGrammar()->wrap($qualified);

                return $this->whereRaw(
                    "LOWER({$wrapped}) LIKE ?",
                    [mb_strtolower($value, 'UTF-8')],
                    $boolean
                );
            };

            QueryBuilder::macro($method, $handler);
            EloquentBuilder::macro($method, $handler);
        };

        $registerLikeMacro('whereLikeInsensitive', 'and');
        $registerLikeMacro('orWhereLikeInsensitive', 'or');
    }
}
