<?php

namespace Database\Seeders;

use App\Models\Setting\Setting;
use App\Services\CourierSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShippingCourierSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            Setting::query()->firstOrCreate(['key' => CourierSettingsService::KEY], ['data' => []]);
            $setting = Setting::query()->where('key', CourierSettingsService::KEY)->lockForUpdate()->firstOrFail();
            $data = $setting->data;
            $data['enabled_couriers'] ??= CourierSettingsService::DEFAULT_COURIERS;
            $data['rate_method'] ??= 'coordinates';
            $data['logos'] = [...CourierSettingsService::DEFAULT_LOGOS, ...($data['logos'] ?? [])];
            $setting->update(['data' => $data]);
        });
    }
}
