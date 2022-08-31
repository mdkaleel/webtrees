<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2023 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Fisharebest\Webtrees\Http\Middleware;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\DB\WebtreesSchema;
use Fisharebest\Webtrees\Services\MigrationService;
use Fisharebest\Webtrees\Webtrees;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function array_map;
use function implode;
use function microtime;
use function str_contains;
use function usort;

/**
 * Middleware to update the database automatically, after an upgrade.
 */
class UpdateDatabaseSchema implements MiddlewareInterface
{
    private MigrationService $migration_service;

    /**
     * @param MigrationService $migration_service
     */
    public function __construct(MigrationService $migration_service)
    {
        $this->migration_service = $migration_service;
    }

    /**
     * Update the database schema, if necessary.
     *
     * @param ServerRequestInterface  $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->migration_service
            ->updateSchema('\Fisharebest\Webtrees\Schema', 'WT_SCHEMA_VERSION', Webtrees::SCHEMA_VERSION);

        $platform = DB::getDBALConnection()->getDatabasePlatform();
        $platform->registerDoctrineTypeMapping(dbType: 'enum', doctrineType: 'string');

        $schema_manager = DB::getDBALConnection()->createSchemaManager();
        $comparator     = $schema_manager->createComparator();
        $source         = $schema_manager->introspectSchema();
        $target         = WebtreesSchema::schema();

        // doctrine/dbal 4.0 does not have the concept of "saveSQL"
        foreach ($source->getTables() as $table) {
            if (!$target->hasTable($table->getName())) {
                $source->dropTable($table->getName());
            }
        }

        $schema_diff = $comparator->compareSchemas(oldSchema: $source, newSchema: $target);
        $queries     = $platform->getAlterSchemaSQL(diff: $schema_diff);

        // Workaround for https://github.com/doctrine/dbal/issues/6092
        $phase = static fn (string $query): int => match (true) {
            str_contains(haystack: $query, needle: 'DROP FOREIGN KEY') => 1,
            default                                                    => 2,
            str_contains(haystack: $query, needle: 'FOREIGN KEY')      => 3,
        };
        $fn = static fn (string $query1, string $query2): int => $phase(query: $query1) <=> $phase(query: $query2);
        usort(array: $queries, callback: $fn);

        // SQLite, PostgreSQL and SQL-Server all support DDL in transactions
        if (DB::getDBALConnection()->getDriver() instanceof AbstractMySQLDriver) {
            $queries = [
                'SET FOREIGN_KEY_CHECKS := 0',
                ...$queries,
                'SET FOREIGN_KEY_CHECKS := 1',
            ];
        } else {
            $queries = [
                'START TRANSACTION',
                ...$queries,
                'COMMIT',
            ];
        }


        foreach ($queries as $query) {
            echo '<p>', $query, ';';
            $t = microtime(true);

            try {
                DB::getDBALConnection()->executeStatement(sql: $query);
                //echo ' /* ', (int) (1000.8 * (microtime(true) - $t)), 'ms */';
            } catch (Throwable $ex) {
                echo ' <span style="color:red">', $ex->getMessage(), '</span>';
            }
            echo '</p>';
        }

        exit;

        return $handler->handle($request);
    }
}
