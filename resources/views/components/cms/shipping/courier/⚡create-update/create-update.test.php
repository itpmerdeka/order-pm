<?php

use App\Models\Setting\Setting;
use App\Models\Spatie\Permission;
use App\Models\User;
use App\Services\CourierSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.biteship.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['api.biteship.com/v1/couriers' => Http::response(['couriers' => [
        ['courier_code' => 'gojek', 'courier_name' => 'GoSend', 'courier_service_name' => 'Instant'],
        ['courier_code' => 'grab', 'courier_name' => 'GrabExpress', 'courier_service_name' => 'Instant'],
    ]])]);
    $this->actor = User::factory()->create();
    foreach (['show', 'update'] as $ability) {
        $this->actor->givePermissionTo(Permission::findOrCreate($ability.Setting::class, 'api'));
    }
});

it('loads the selected courier and saves active and inactive statuses', function () {
    $component = Livewire::actingAs($this->actor)->test('cms.shipping.courier.create-update')
        ->dispatch('set-action', code: 'gojek')->assertSet('name', 'GoSend')->assertSet('enabled', 1)
        ->assertSee('wire:model.number="enabled"', false)
        ->set('enabled', '1')->call('submit')->assertHasNoErrors()->assertDispatched('reset-parent-page');
    expect(app(CourierSettingsService::class)->enabledCodes())->toContain('gojek');
    $component->dispatch('set-action', code: 'gojek')->assertSet('enabled', 1)
        ->set('enabled', '0')->call('submit')->assertHasNoErrors();
    expect(app(CourierSettingsService::class)->enabledCodes())->not->toContain('gojek');
});

it('locks the selected courier identity', function () {
    $component = Livewire::actingAs($this->actor)->test('cms.shipping.courier.create-update')
        ->dispatch('set-action', code: 'gojek');
    expect(fn () => $component->set('code', 'grab'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('uploads a logo and reloads its preview without leaking it to another courier', function () {
    Storage::fake('public');
    $component = Livewire::actingAs($this->actor)->test('cms.shipping.courier.create-update')
        ->dispatch('set-action', code: 'gojek')
        ->assertSet('oldImage', asset(CourierSettingsService::DEFAULT_LOGOS['gojek']))
        ->assertSee('wire:model="image"', false)
        ->set('image', UploadedFile::fake()->image('gojek.png'))
        ->call('submit')->assertHasNoErrors()->assertDispatched('reset-parent-page');
    $path = Setting::query()->where('key', CourierSettingsService::KEY)->sole()->data['logos']['gojek'];
    Storage::disk('public')->assertExists($path);
    $component->dispatch('set-action', code: 'gojek')->assertSet('image', null)
        ->assertSet('oldImage', Storage::disk('public')->url($path))
        ->set('image', UploadedFile::fake()->image('unsaved.png'))
        ->dispatch('set-action', code: 'grab')->assertSet('image', null)
        ->assertSet('oldImage', asset(CourierSettingsService::DEFAULT_LOGOS['grab']))
        ->call('submit')->assertHasNoErrors();
    expect(Setting::query()->where('key', CourierSettingsService::KEY)->sole()->data['logos'])
        ->toBe(['gojek' => $path]);
});

it('validates oversized courier uploads', function () {
    Storage::fake('public');
    Livewire::actingAs($this->actor)->test('cms.shipping.courier.create-update')
        ->dispatch('set-action', code: 'gojek')
        ->set('image', UploadedFile::fake()->image('logo.png')->size(2049))
        ->call('submit')->assertHasErrors(['image' => 'max']);
    expect(Setting::query()->count())->toBe(0);
});

it('rejects submission before selecting a courier', function () {
    Livewire::actingAs($this->actor)->test('cms.shipping.courier.create-update')
        ->call('submit')->assertHasErrors(['code']);
    expect(Setting::query()->count())->toBe(0);
});

it('rejects an unknown courier', function () {
    Livewire::actingAs($this->actor)->test('cms.shipping.courier.create-update')
        ->dispatch('set-action', code: 'unknown')->assertNotFound();
});

it('denies loading and saving without permission', function () {
    Livewire::actingAs(User::factory()->create())->test('cms.shipping.courier.create-update')
        ->dispatch('set-action', code: 'gojek')->assertForbidden();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('show'.Setting::class, 'api'));
    Livewire::actingAs($viewer)->test('cms.shipping.courier.create-update')
        ->dispatch('set-action', code: 'gojek')->set('enabled', true)->call('submit')->assertForbidden();
    expect(Setting::query()->count())->toBe(0);
});
