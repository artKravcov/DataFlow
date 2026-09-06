<?php

namespace App\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class ReadinessController extends Controller
{
    public function __invoke(Migrator $migrator): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            Redis::connection()->ping();

            if (! $migrator->repositoryExists()) {
                return $this->notReady();
            }

            $migrationFiles = array_keys(
                $migrator->getMigrationFiles(database_path('migrations')),
            );

            $ranMigrations = $migrator->getRepository()->getRan();

            if (array_diff($migrationFiles, $ranMigrations) !== []) {
                return $this->notReady();
            }
        } catch (Throwable) {
            return $this->notReady();
        }

        return response()->json(['status' => 'ready']);
    }

    private function notReady(): JsonResponse
    {
        return response()->json(['status' => 'not_ready'], 503);
    }
}
