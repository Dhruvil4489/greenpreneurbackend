<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\State;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SplFileObject;

class ImportIndiaDistricts extends Command
{
    protected $signature = 'import:india-districts {csv : Path to official LGD/Government of India CSV, e.g. storage/app/india_districts.csv}';

    protected $description = 'Import Indian States/UTs and districts from an official LGD/Government of India CSV.';

    public function handle(): int
    {
        if (! Schema::hasTable('states') || ! Schema::hasTable('districts')) {
            $this->error('The states and districts tables must exist before importing. Run the provided manual PostgreSQL SQL first.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn('districts', 'state_id')) {
            $this->error('The districts table must include state_id before importing. Run the provided manual PostgreSQL SQL first.');

            return self::FAILURE;
        }

        $path = $this->resolveCsvPath((string) $this->argument('csv'));
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("CSV file is not readable: {$path}");

            return self::FAILURE;
        }

        $statesBefore = State::query()->count();
        $districtsBefore = District::query()->count();
        $this->info("Existing states: {$statesBefore}");
        $this->info("Existing districts: {$districtsBefore}");

        [$headers, $rows] = $this->readCsv($path);
        if (! $this->hasSupportedHeaders($headers)) {
            $this->error('CSV headers must be state_name,district_name or state_code,state_name,district_code,district_name.');

            return self::FAILURE;
        }

        $stateRows = [];
        $districtRows = [];
        foreach ($rows as $row) {
            $stateName = $this->cleanName($row['state_name'] ?? '');
            $districtName = $this->cleanName($row['district_name'] ?? '');

            if ($stateName === '' || $districtName === '') {
                continue;
            }

            $stateKey = Str::lower($stateName);
            $districtKey = $stateKey . '|' . Str::lower($districtName);
            $stateRows[$stateKey] = $stateName;
            $districtRows[$districtKey] = [
                'state_name' => $stateName,
                'district_name' => $districtName,
            ];
        }

        if ($stateRows === [] || $districtRows === []) {
            $this->error('No valid state/district rows were found in the CSV.');

            return self::FAILURE;
        }

        $createdStates = 0;
        $updatedStates = 0;
        $createdDistricts = 0;
        $updatedDistricts = 0;

        DB::transaction(function () use ($stateRows, $districtRows, &$createdStates, &$updatedStates, &$createdDistricts, &$updatedDistricts): void {
            $stateIdByName = [];

            foreach ($stateRows as $stateName) {
                $state = State::query()
                    ->whereRaw('LOWER(name) = ?', [Str::lower($stateName)])
                    ->first();

                if ($state) {
                    $state->forceFill([
                        'name' => $stateName,
                        'status' => 'active',
                    ])->save();
                    $updatedStates++;
                } else {
                    $state = State::query()->updateOrCreate(
                        ['name' => $stateName],
                        ['status' => 'active']
                    );
                    $createdStates++;
                }

                $stateIdByName[Str::lower($stateName)] = (string) $state->id;
            }

            foreach ($districtRows as $districtRow) {
                $stateId = $stateIdByName[Str::lower($districtRow['state_name'])] ?? null;
                if (! $stateId) {
                    continue;
                }

                $district = District::query()
                    ->where('state_id', $stateId)
                    ->whereRaw('LOWER(name) = ?', [Str::lower($districtRow['district_name'])])
                    ->first();

                if ($district) {
                    $district->forceFill([
                        'name' => $districtRow['district_name'],
                        'status' => 'active',
                    ])->save();
                    $updatedDistricts++;
                } else {
                    District::query()->updateOrCreate(
                        [
                            'state_id' => $stateId,
                            'name' => $districtRow['district_name'],
                        ],
                        ['status' => 'active']
                    );
                    $createdDistricts++;
                }
            }
        });

        $statesAfter = State::query()->count();
        $districtsAfter = District::query()->count();

        $this->info("Created states: {$createdStates}");
        $this->info("Updated states: {$updatedStates}");
        $this->info("Created districts: {$createdDistricts}");
        $this->info("Updated districts: {$updatedDistricts}");
        $this->info("States after import: {$statesAfter}");
        $this->info("Districts after import: {$districtsAfter}");

        return self::SUCCESS;
    }

    private function resolveCsvPath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }

    private function readCsv(string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $headers = [];
        $rows = [];
        foreach ($file as $index => $columns) {
            if (! is_array($columns) || $columns === [null]) {
                continue;
            }

            if ($index === 0) {
                $headers = array_map(fn ($header) => $this->normalizeHeader((string) $header), $columns);
                continue;
            }

            $row = [];
            foreach ($headers as $columnIndex => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = trim((string) ($columns[$columnIndex] ?? ''));
            }

            $rows[] = $row;
        }

        return [$headers, $rows];
    }

    private function hasSupportedHeaders(array $headers): bool
    {
        $headerSet = array_flip($headers);
        $hasSimpleFormat = isset($headerSet['state_name'], $headerSet['district_name']);
        $hasLgdFormat = isset($headerSet['state_code'], $headerSet['state_name'], $headerSet['district_code'], $headerSet['district_name']);

        return $hasSimpleFormat || $hasLgdFormat;
    }

    private function normalizeHeader(string $header): string
    {
        return Str::of($header)
            ->trim()
            ->lower()
            ->replace([' ', '-', '/', '\\'], '_')
            ->replaceMatches('/[^a-z0-9_]/', '')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->toString();
    }

    private function cleanName(string $value): string
    {
        return trim((string) Str::of($value)->replaceMatches('/\s+/', ' '));
    }
}
