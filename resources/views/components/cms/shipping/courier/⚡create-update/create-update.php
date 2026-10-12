<?php

use App\Actions\Cms\Shipping\UpdateCourierSettingAction;
use App\Data\Cms\CourierSettingData;
use App\Models\Setting\Setting;
use App\Services\CourierSettingsService;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $modelInstance = Setting::class;

    #[Locked]
    public ?string $code = null;

    #[Locked]
    public string $name = '';

    public int $enabled = 0;

    public ?UploadedFile $image = null;

    #[Locked]
    public ?string $oldImage = null;

    #[On('set-action')]
    public function setAction(string $code, CourierSettingsService $courierSettings): void
    {
        Gate::authorize('show'.$this->modelInstance);
        $this->resetValidation();
        $this->reset(['code', 'name', 'enabled', 'image', 'oldImage']);
        try {
            $courier = collect($courierSettings->couriers())->firstWhere('code', $code);
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('toast', type: 'error', message: 'Gagal mengambil daftar kurir. Silakan coba lagi.');

            return;
        }
        abort_unless($courier, 404);
        $this->code = $courier['code'];
        $this->name = $courier['name'];
        $this->enabled = (int) in_array($code, $courierSettings->enabledCodes(), true);
        $this->oldImage = $courier['logo_url'];
    }

    public function submit(UpdateCourierSettingAction $action): void
    {
        Gate::authorize('update'.$this->modelInstance);
        $this->validate([
            'code' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'extensions:jpg,jpeg,png', 'max:2048'],
        ]);

        try {
            $action->handle(new CourierSettingData($this->code, (bool) $this->enabled, $this->image), auth()->user());
        } catch (RuntimeException $exception) {
            report($exception);
            $this->dispatch('toast', type: 'error', message: 'Gagal menyimpan pengaturan kurir. Silakan coba lagi.');

            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Courier updated successfully.');
        $this->dispatch('reset-parent-page');
        Flux::modal('defaultModal')->close();
    }
};
