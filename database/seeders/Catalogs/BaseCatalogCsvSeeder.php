<?php

namespace Database\Seeders\Catalogs;

use Database\Seeders\Concerns\ReadsCsvRows;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

abstract class BaseCatalogCsvSeeder extends Seeder
{
    use ReadsCsvRows;

    abstract protected function tableName(): string;

    abstract protected function modelClass(): string;

    abstract protected function csvRelativePath(): string;

    abstract protected function keyColumn(): string;

    abstract protected function nameColumn(): string;

    public function run(): void
    {
        if (! Schema::hasTable($this->tableName())) {
            $this->command?->warn("Tabla {$this->tableName()} no existe, se omite ".static::class.'.');

            return;
        }

        $rows = $this->readCsvRows(base_path($this->csvRelativePath()));
        if ($rows === []) {
            $this->command?->warn('No se encontraron datos fuente para '.static::class.'.');

            return;
        }

        $modelClass = $this->modelClass();
        $seeded = 0;

        foreach ($rows as $row) {
            $code = $this->csvValue($row, $this->keyColumn());
            $name = $this->csvValue($row, $this->nameColumn());

            if ($code === null || $name === null) {
                continue;
            }

            $modelClass::query()->updateOrCreate(
                [$this->keyColumn() => $code],
                [$this->nameColumn() => $name]
            );

            $seeded++;
        }

        $this->command?->info(static::class." OK: {$seeded} registros procesados.");
    }
}
