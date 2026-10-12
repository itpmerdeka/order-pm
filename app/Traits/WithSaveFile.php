<?php

namespace App\Traits;

use Illuminate\Http\UploadedFile;

trait WithSaveFile
{
    public function saveFile(?UploadedFile $file, string $path = 'file', string $base_file_name = 'file', ?string $disk = null): string|false
    {
        $base_file_name = $base_file_name.'_'.date('d-m-Y').'_';

        if (! $file) {
            return false;
        }

        $filename = $base_file_name.uniqid().'.'.$file->extension();

        return $file->storeAs($path, $filename, $disk === null ? [] : ['disk' => $disk]);
    }
}
