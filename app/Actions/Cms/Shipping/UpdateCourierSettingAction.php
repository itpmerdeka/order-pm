<?php

namespace App\Actions\Cms\Shipping;

use App\Data\Cms\CourierSettingData;
use App\Models\Setting\Setting;
use App\Models\User;
use App\Services\CourierSettingsService;
use App\Traits\WithSaveFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class UpdateCourierSettingAction
{
    use WithSaveFile;

    public function __construct(private CourierSettingsService $courierSettings) {}

    public function handle(CourierSettingData $data, User $actor): Setting
    {
        Gate::forUser($actor)->authorize('update'.Setting::class);
        validator(['image' => $data->image], [
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'extensions:jpg,jpeg,png', 'max:2048'],
        ])->validate();

        if (! in_array($data->code, array_column($this->courierSettings->couriers(), 'code'), true)) {
            throw ValidationException::withMessages(['code' => 'Kurir tidak tersedia di Biteship.']);
        }

        $newLogo = null;
        $oldLogo = null;
        try {
            $setting = DB::transaction(function () use ($data, &$newLogo, &$oldLogo): Setting {
                Setting::query()->firstOrCreate(
                    ['key' => CourierSettingsService::KEY],
                    ['data' => ['enabled_couriers' => CourierSettingsService::DEFAULT_COURIERS]],
                );

                $setting = Setting::query()->where('key', CourierSettingsService::KEY)->lockForUpdate()->firstOrFail();
                $enabled = collect($setting->data['enabled_couriers'] ?? CourierSettingsService::DEFAULT_COURIERS);
                $enabled = $data->enabled
                    ? $enabled->push($data->code)->unique()
                    : $enabled->reject(fn (string $code): bool => $code === $data->code);

                $settings = [...$setting->data, 'enabled_couriers' => $enabled->sort()->values()->all()];
                if ($data->image !== null) {
                    $newLogo = $this->saveFile($data->image, 'shipping/couriers', $data->code, 'public');
                    if ($newLogo === false) {
                        throw new RuntimeException('Gagal menyimpan logo kurir.');
                    }
                    $oldLogo = $settings['logos'][$data->code] ?? null;
                    $settings['logos'][$data->code] = $newLogo;
                }
                $setting->update(['data' => $settings]);

                return $setting;
            });
        } catch (Throwable $exception) {
            if (is_string($newLogo)) {
                Storage::disk('public')->delete($newLogo);
            }
            throw $exception;
        }

        if (is_string($oldLogo) && str_starts_with($oldLogo, 'shipping/couriers/')) {
            DB::afterCommit(fn () => Storage::disk('public')->delete($oldLogo));
        }

        return $setting;
    }
}
