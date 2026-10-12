<?php

namespace App\Services;

use App\Models\Setting\Setting;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CourierSettingsService
{
    public const KEY = 'shipping_couriers';

    public const DEFAULT_COURIERS = ['jne', 'jnt', 'gojek', 'grab'];

    public const DEFAULT_LOGOS = [
        'jne' => 'images/courier-jne.svg',
        'jnt' => 'images/courier-jnt.png',
        'gojek' => 'images/courier-gojek.svg',
        'grab' => 'images/courier-grab.png',
    ];

    public const COORDINATE_COURIERS = ['gojek', 'grab', 'paxel', 'lalamove', 'borzo'];

    public const RATE_METHODS = ['coordinates', 'area_id'];

    public function __construct(private BiteshipService $biteshipService) {}

    /** @return list<string> */
    public function enabledCodes(): array
    {
        $setting = Setting::query()->where('key', self::KEY)->first();

        return $setting?->data['enabled_couriers'] ?? self::DEFAULT_COURIERS;
    }

    public function rateMethod(): string
    {
        return Setting::query()->where('key', self::KEY)->first()?->data['rate_method'] ?? 'coordinates';
    }

    public function usesAreaIds(): bool
    {
        return $this->rateMethod() === 'area_id';
    }

    /** @return array<string, string> */
    public function logoUrls(): array
    {
        $logos = Setting::query()->where('key', self::KEY)->first()?->data['logos'] ?? [];

        return collect([...self::DEFAULT_LOGOS, ...$logos])
            ->map(fn (string $path): string => str_starts_with($path, 'shipping/couriers/')
                ? Storage::disk('public')->url($path)
                : asset($path))
            ->all();
    }

    /** @return list<array{code: string, name: string, services: string, logo_url: ?string}> */
    public function couriers(): array
    {
        $logoUrls = $this->logoUrls();
        $couriers = collect($this->biteshipService->couriers())
            ->filter(fn (array $courier): bool => filled($courier['courier_code'] ?? null))
            ->groupBy('courier_code')
            ->map(fn ($services, string $code): array => [
                'code' => $code,
                'name' => $services->first()['courier_name'] ?? $code,
                'services' => $services->pluck('courier_service_name')->filter()->unique()->implode(', '),
                'logo_url' => $logoUrls[$code] ?? null,
            ])
            ->sortBy('name')
            ->values()
            ->all();

        if ($couriers === []) {
            throw new RuntimeException('Daftar kurir Biteship belum tersedia.');
        }

        return $couriers;
    }
}
