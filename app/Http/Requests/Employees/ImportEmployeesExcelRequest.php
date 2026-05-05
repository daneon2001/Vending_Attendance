<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;

class ImportEmployeesExcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx',
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream,application/zip',
                'max:10240',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Debes seleccionar un archivo Excel.',
            'file.file' => 'El archivo seleccionado no es valido.',
            'file.mimes' => 'El archivo debe tener extension .xlsx.',
            'file.mimetypes' => 'El archivo debe ser un Excel valido.',
            'file.max' => 'El archivo no debe exceder 10 MB.',
        ];
    }
}
