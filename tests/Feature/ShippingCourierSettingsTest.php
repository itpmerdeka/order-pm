<?php

use App\Actions\Cms\Shipping\UpdateCourierSettingAction;
use App\Actions\Cms\Shipping\UpdateShippingRateSettingsAction;
use App\Actions\Ecommerce\Shipping\GetShippingRatesAction;
use App\Data\Checkout\GuestCheckoutData;
use App\Data\Checkout\ShippingRatesData;
use App\Data\Cms\CourierSettingData;
use App\Data\Cms\ShippingRateSettingsData;
use App\Models\Location\Location;
use App\Models\Menu\Menu;
use App\Models\Product\Product;
use App\Models\Product\ProductFlat;
use App\Models\Setting\Setting;
use App\Models\Shop\Shop;
use App\Models\Spatie\Permission;
use App\Models\Spatie\Role;
use App\Models\User;
use App\Services\CourierSettingsService;
use Database\Seeders\ShippingCourierSeeder;
use Database\Seeders\SuperadminMenuSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.biteship.key' => 'test-key']);
    Http::preventStrayRequests();
    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(Permission::findOrCreate('update'.Setting::class, 'api'));
    $this->shop = Shop::factory()->create();
    $this->origin = Location::factory()->for($this->shop)->create([
        'type' => 'origin', 'biteship_area_id' => null, 'area_string' => null,
        'latitude' => '-6.2', 'longitude' => '106.8',
    ]);
    $product = Product::factory()->for($this->shop)->create();
    $this->flat = ProductFlat::factory()->for($product)->create(['shop_id' => $this->shop->id, 'price' => 50000, 'weight' => 250]);
    $this->data = ShippingRatesData::fromArray($this->shop->id, '', [$this->flat->id => 2], -6.21, 106.81);
    Http::fake([
        'api.biteship.com/v1/couriers' => Http::response(['success' => true, 'couriers' => [
            ['courier_code' => 'jne', 'courier_name' => 'JNE', 'courier_service_name' => 'Regular'],
            ['courier_code' => 'gojek', 'courier_name' => 'GoSend', 'courier_service_name' => 'Instant'],
            ['courier_code' => 'gojek', 'courier_name' => 'GoSend', 'courier_service_name' => 'Same Day'],
            ['courier_code' => 'grab', 'courier_name' => 'GrabExpress', 'courier_service_name' => 'Instant'],
        ]]),
        'api.biteship.com/v1/rates/couriers' => Http::response(['success' => true, 'pricing' => [
            ['courier_code' => 'jne', 'courier_service_code' => 'reg', 'price' => 15000],
            ['courier_code' => 'gojek', 'courier_service_code' => 'instant', 'price' => 18000],
            ['courier_code' => 'grab', 'courier_service_code' => 'instant', 'price' => 20000],
        ]]),
    ]);
});

it('defaults to coordinates and JNE JNT Gojek and Grab', function () {
    $service = app(CourierSettingsService::class);
    expect($service->rateMethod())->toBe('coordinates')
        ->and($service->enabledCodes())->toBe(['jne', 'jnt', 'gojek', 'grab']);
    app(GetShippingRatesAction::class)->handle($this->data);
    Http::assertSent(fn (Request $request): bool => $request['couriers'] === implode(',', CourierSettingsService::DEFAULT_COURIERS)
        && $request['origin_latitude'] === -6.2 && $request['destination_longitude'] === 106.81
        && ! array_key_exists('origin_area_id', $request->data()) && ! array_key_exists('destination_area_id', $request->data())
        && $request['items'][0]['quantity'] === 2 && $request['items'][0]['weight'] === 250);
    Http::assertSentCount(1);
});

it('groups the catalog by company and retains its services', function () {
    $couriers = app(CourierSettingsService::class)->couriers();
    expect($couriers)->toHaveCount(3)
        ->and(collect($couriers)->firstWhere('code', 'gojek')['services'])->toBe('Instant, Same Day');
});

it('stores a public courier logo and preserves other settings and logos', function () {
    Storage::fake('public');
    $setting = Setting::query()->create(['key' => CourierSettingsService::KEY, 'data' => [
        'enabled_couriers' => ['jne'], 'rate_method' => 'area_id', 'logos' => ['grab' => 'images/courier-grab.png'],
    ]]);
    $action = app(UpdateCourierSettingAction::class);
    $action->handle(new CourierSettingData('gojek', true, UploadedFile::fake()->image('gojek.png')), $this->actor);
    $path = $setting->fresh()->data['logos']['gojek'];
    Storage::disk('public')->assertExists($path);
    expect($path)->toStartWith('shipping/couriers/gojek_')
        ->and(app(CourierSettingsService::class)->logoUrls()['gojek'])->toBe(Storage::disk('public')->url($path));
    $action->handle(new CourierSettingData('gojek', false), $this->actor);
    expect($setting->fresh()->data)->toBe([
        'enabled_couriers' => ['jne'], 'rate_method' => 'area_id',
        'logos' => ['grab' => 'images/courier-grab.png', 'gojek' => $path],
    ]);
    Storage::disk('public')->assertExists($path);
});

it('replaces an uploaded logo and deletes only the old upload', function () {
    Storage::fake('public');
    $action = app(UpdateCourierSettingAction::class);
    $setting = $action->handle(new CourierSettingData('gojek', true, UploadedFile::fake()->image('first.png')), $this->actor);
    $oldPath = $setting->data['logos']['gojek'];
    $setting = $action->handle(new CourierSettingData('gojek', true, UploadedFile::fake()->image('second.jpg')), $this->actor);
    $newPath = $setting->data['logos']['gojek'];
    expect($newPath)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($newPath);
    expect(is_file(public_path(CourierSettingsService::DEFAULT_LOGOS['gojek'])))->toBeTrue();
});

it('rejects invalid courier logos before storing anything', function (Closure $file) {
    Storage::fake('public');
    expect(fn () => app(UpdateCourierSettingAction::class)->handle(new CourierSettingData('gojek', true, $file()), $this->actor))
        ->toThrow(ValidationException::class);
    expect(Setting::query()->count())->toBe(0);
    Storage::disk('public')->assertDirectoryEmpty('shipping/couriers');
})->with([
    'non image' => [fn (): UploadedFile => UploadedFile::fake()->create('logo.txt', 10, 'text/plain')],
    'oversized image' => [fn (): UploadedFile => UploadedFile::fake()->image('logo.png')->size(2049)],
    'unsupported image' => [fn (): UploadedFile => UploadedFile::fake()->image('logo.gif')],
    'misleading extension' => [fn (): UploadedFile => UploadedFile::fake()->image('logo.php')],
]);

it('does not store courier logos for an unauthorized actor', function () {
    Storage::fake('public');
    expect(fn () => app(UpdateCourierSettingAction::class)->handle(
        new CourierSettingData('gojek', true, UploadedFile::fake()->image('logo.png')), User::factory()->create(),
    ))->toThrow(AuthorizationException::class);
    Storage::disk('public')->assertDirectoryEmpty('shipping/couriers');
    expect(Setting::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rolls back courier settings when storing the logo fails', function () {
    Storage::fake('public');
    $action = Mockery::mock(UpdateCourierSettingAction::class, [app(CourierSettingsService::class)])->makePartial();
    $action->shouldReceive('saveFile')->once()->andReturn(false);
    expect(fn () => $action->handle(new CourierSettingData('gojek', true, UploadedFile::fake()->image('logo.png')), $this->actor))
        ->toThrow(RuntimeException::class, 'Gagal menyimpan logo kurir.');
    expect(Setting::query()->count())->toBe(0);
});

it('seeds four active couriers with bundled default logos', function () {
    $this->seed(ShippingCourierSeeder::class);
    $service = app(CourierSettingsService::class);
    $setting = Setting::query()->where('key', CourierSettingsService::KEY)->sole();
    expect($service->enabledCodes())->toBe(['jne', 'jnt', 'gojek', 'grab'])
        ->and($service->rateMethod())->toBe('coordinates')
        ->and($setting->data['logos'])->toBe(CourierSettingsService::DEFAULT_LOGOS);
    foreach (CourierSettingsService::DEFAULT_LOGOS as $code => $path) {
        expect(is_file(public_path($path)))->toBeTrue()
            ->and($service->logoUrls()[$code])->toBe(asset($path));
    }
    Http::assertNothingSent();
});

it('seeds missing logos without resetting custom logos or admin settings', function () {
    $setting = Setting::query()->create(['key' => CourierSettingsService::KEY, 'data' => [
        'enabled_couriers' => [], 'rate_method' => 'area_id', 'custom' => true,
        'logos' => ['gojek' => 'shipping/couriers/custom.png'],
    ]]);
    $this->seed(ShippingCourierSeeder::class);
    $this->seed(ShippingCourierSeeder::class);
    expect(Setting::query()->count())->toBe(1)
        ->and($setting->fresh()->data)->toBe([
            'enabled_couriers' => [], 'rate_method' => 'area_id', 'custom' => true,
            'logos' => [...CourierSettingsService::DEFAULT_LOGOS, 'gojek' => 'shipping/couriers/custom.png'],
        ]);
});

it('saves courier toggles and rate method without losing other settings', function () {
    $methodAction = app(UpdateShippingRateSettingsAction::class);
    $courierAction = app(UpdateCourierSettingAction::class);
    $methodAction->handle(new ShippingRateSettingsData('area_id'), $this->actor);
    $courierAction->handle(new CourierSettingData('gojek', true), $this->actor);
    $courierAction->handle(new CourierSettingData('grab', true), $this->actor);
    $courierAction->handle(new CourierSettingData('jne', false), $this->actor);
    $service = app(CourierSettingsService::class);
    expect($service->rateMethod())->toBe('area_id')
        ->and($service->enabledCodes())->toContain('gojek', 'grab')->not->toContain('jne');
    $methodAction->handle(new ShippingRateSettingsData('coordinates'), $this->actor);
    expect($service->enabledCodes())->toContain('gojek', 'grab')->not->toContain('jne');
});

it('uses the supplied actor to authorize settings changes', function () {
    $this->actingAs($this->actor);
    $unauthorized = User::factory()->create();
    expect(fn () => app(UpdateCourierSettingAction::class)->handle(new CourierSettingData('gojek', true), $unauthorized))->toThrow(AuthorizationException::class);
    expect(fn () => app(UpdateShippingRateSettingsAction::class)->handle(new ShippingRateSettingsData('coordinates'), $unauthorized))->toThrow(AuthorizationException::class);
    expect(Setting::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects unknown couriers and invalid methods', function () {
    expect(fn () => app(UpdateCourierSettingAction::class)->handle(new CourierSettingData('unknown', true), $this->actor))->toThrow(ValidationException::class);
    expect(fn () => app(UpdateShippingRateSettingsAction::class)->handle(new ShippingRateSettingsData('postal_code'), $this->actor))->toThrow(ValidationException::class);
    expect(Setting::query()->count())->toBe(0);
});

it('checks only enabled instant couriers with coordinates and fresh rates', function () {
    Setting::query()->create(['key' => CourierSettingsService::KEY, 'data' => ['enabled_couriers' => ['gojek', 'grab']]]);
    $action = app(GetShippingRatesAction::class);
    expect(array_column($action->handle($this->data), 'courier_code'))->toBe(['gojek', 'grab']);
    $action->handle($this->data);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['couriers'] === 'gojek,grab'
        && $request['origin_latitude'] === -6.2 && $request['origin_longitude'] === 106.8
        && $request['destination_latitude'] === -6.21 && $request['destination_longitude'] === 106.81);
});

it('uses only area IDs when the area method is selected', function () {
    $this->origin->update(['biteship_area_id' => 'origin-area', 'latitude' => null, 'longitude' => null]);
    Setting::query()->create(['key' => CourierSettingsService::KEY, 'data' => ['rate_method' => 'area_id', 'enabled_couriers' => ['jne']]]);
    $data = ShippingRatesData::fromArray($this->shop->id, 'destination-area', [$this->flat->id => 1]);
    app(GetShippingRatesAction::class)->handle($data);
    Http::assertSent(fn (Request $request): bool => $request['origin_area_id'] === 'origin-area'
        && $request['destination_area_id'] === 'destination-area'
        && ! array_key_exists('origin_latitude', $request->data()) && ! array_key_exists('destination_latitude', $request->data()));
});

it('requires area IDs only in area mode', function () {
    Setting::query()->create(['key' => CourierSettingsService::KEY, 'data' => ['rate_method' => 'area_id']]);
    expect(fn () => app(GetShippingRatesAction::class)->handle($this->data))->toThrow(Exception::class, 'Informasi lokasi toko belum lengkap.');
    $this->origin->update(['biteship_area_id' => 'origin-area']);
    expect(fn () => app(GetShippingRatesAction::class)->handle($this->data))->toThrow(Exception::class, 'Pilih alamat pengiriman terlebih dahulu.');
    Http::assertNothingSent();
});

it('requires valid coordinates before calling Biteship', function (?float $latitude, ?float $longitude) {
    $data = ShippingRatesData::fromArray($this->shop->id, '', [$this->flat->id => 1], $latitude, $longitude);
    expect(fn () => app(GetShippingRatesAction::class)->handle($data))->toThrow(Exception::class, 'Lengkapi titik lokasi toko dan alamat pengiriman di peta.');
    Http::assertNothingSent();
})->with([[null, null], [91.0, 106.8], [-6.2, 181.0]]);

it('rejects empty courier selection without contacting Biteship', function () {
    Setting::query()->create(['key' => CourierSettingsService::KEY, 'data' => ['enabled_couriers' => []]]);
    expect(fn () => app(GetShippingRatesAction::class)->handle($this->data))->toThrow(Exception::class, 'Belum ada kurir pengiriman yang diaktifkan.');
    Http::assertNothingSent();
});

it('changes cached rates when the courier selection changes', function () {
    $setting = Setting::query()->create(['key' => CourierSettingsService::KEY, 'data' => ['enabled_couriers' => ['jne']]]);
    $action = app(GetShippingRatesAction::class);
    $action->handle($this->data);
    $action->handle($this->data);
    Http::assertSentCount(1);
    $setting->update(['data' => ['enabled_couriers' => ['gojek']]]);
    expect(array_column($action->handle($this->data), 'courier_code'))->toBe(['gojek']);
    Http::assertSentCount(2);
});

it('accepts a guest address without area and validates area mode', function () {
    $input = ['contact_name' => 'Buyer', 'contact_phone' => '08123456789', 'email' => 'buyer@example.test',
        'address' => 'Jalan Utama', 'postal_code' => '10110', 'latitude' => -6.2, 'longitude' => 106.8];
    $guest = GuestCheckoutData::fromArray($input);
    expect($guest->areaId)->toBeNull()->and($guest->areaString)->toBeNull();
    expect(fn () => GuestCheckoutData::fromArray($input, true))->toThrow(ValidationException::class);
});

it('has nullable area columns in the location database', function () {
    $columns = collect(Schema::getColumns('locations'))->keyBy('name');
    expect($columns['biteship_area_id']['nullable'])->toBeTrue()
        ->and($columns['area_string']['nullable'])->toBeTrue()
        ->and($this->origin->fresh()->biteship_area_id)->toBeNull();
});

it('adds the courier menu and protects the CMS page', function () {
    Role::findOrCreate('superadmin', 'api');
    $this->seed(SuperadminMenuSeeder::class);
    $menu = Menu::query()->where('url', 'cms.shipping.courier')->sole();
    expect($menu->name)->toBe('Couriers')->and($menu->icon)->toBe('truck');
    $this->get('/cms/shipping/courier')->assertRedirect(route('login'));
});
