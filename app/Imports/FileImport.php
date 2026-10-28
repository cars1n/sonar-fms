<?php

namespace App\Imports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class FileImport implements ToCollection, WithCalculatedFormulas, WithHeadingRow, WithStartRow
{
    use Importable;

    public Model $import;

    public function __construct(Model $import)
    {
        $this->import = $import;
    }

    public function collection(Collection $collection): void
    {
        ray($collection);
    }

    public function headingRow(): int
    {
        return $this->import->heading_row;
    }

    public function startRow(): int
    {
        return $this->import->starting_row;
    }
}
