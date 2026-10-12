<?php

namespace App\Data\Cms;

use Illuminate\Http\UploadedFile;

final readonly class CourierSettingData
{
    public function __construct(public string $code, public bool $enabled, public ?UploadedFile $image = null) {}
}
