<?php

use App\Actions\Ecommerce\Payment\CreatePaymentAction;
use App\Actions\Ecommerce\Payment\RefreshPaymentStatusAction;
use App\Data\Payments\PaymentMethodData;
use App\Enums\PaymentGatewayDriver;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Services\Payments\PaymentFeeCalculator;
use App\Services\Payments\PaymentGatewayManager;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public Order $order;

    public ?Payment $payment = null;

    public string $paymentMethod = '';

    public bool $isPaid = false;

    #[Locked]
    public array $paymentMethods = [];

    #[Locked]
    public bool $hasPerformedFinalExpiryRefresh = false;

    public function mount(
        PaymentGatewayManager $paymentGatewayManager,
        PaymentFeeCalculator $paymentFeeCalculator,
    ): void {
        $token = request()->query('token');
        $accessToken = is_string($token) ? $token : '';

        if ($this->order->user_id !== null) {
            abort_unless(auth()->id() === $this->order->user_id, 404);
        } else {
            abort_unless(
                filled($this->order->access_token)
                && filled($accessToken)
                && hash_equals($this->order->access_token, $accessToken),
                404,
            );
        }

        $this->loadOrder();

        if ($this->isLocallyExpiredPayment()) {
            $this->refreshPaymentStatus();
        }

        if ($this->payment === null) {
            $this->loadPaymentMethods($paymentGatewayManager, $paymentFeeCalculator);
        } elseif (blank($this->payment->transaction_id)) {
            $this->loadPersistedPaymentMethod($paymentGatewayManager, $paymentFeeCalculator);
        }
    }

    public function submit(CreatePaymentAction $createPaymentAction): void
    {
        if ($this->payment !== null && filled($this->payment->transaction_id)) {
            return;
        }

        $availableProviderCodes = collect($this->paymentMethods)
            ->where('isAvailable', true)
            ->pluck('providerCode')
            ->all();

        $this->validate([
            'paymentMethod' => ['required', Rule::in($availableProviderCodes)],
        ]);

        $selectedMethod = $this->selectedPaymentMethod();

        if ($selectedMethod === null) {
            $this->addError('paymentMethod', 'Metode pembayaran yang dipilih tidak tersedia.');

            return;
        }

        try {
            $this->payment = $createPaymentAction->handle(
                order: $this->order,
                paymentMethod: $selectedMethod['method'],
                actor: auth()->user(),
                guestToken: $this->order->user_id === null ? $this->order->access_token : null,
                providerCode: $selectedMethod['providerCode'],
            );
            $this->isPaid = $this->payment->paid_at !== null;
            $this->dispatch(
                'toast',
                type: 'success',
                message: 'Metode pembayaran berhasil dipilih.',
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatchProviderError();
        }
    }

    public function refreshPaymentStatus(): void
    {
        $isFinalExpiryRefresh = $this->isLocallyExpiredPayment()
            && ! $this->hasPerformedFinalExpiryRefresh;

        if (! $this->isPaymentPending() && ! $isFinalExpiryRefresh) {
            return;
        }

        if ($isFinalExpiryRefresh) {
            $this->hasPerformedFinalExpiryRefresh = true;
        }

        try {
            $this->payment = app(RefreshPaymentStatusAction::class)->handle($this->payment);
            $this->loadOrder();
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatchProviderError();
        }
    }

    public function selectedPaymentMethod(): ?array
    {
        return collect($this->paymentMethods)
            ->firstWhere('providerCode', $this->paymentMethod);
    }

    public function paymentStatus(): string
    {
        if ($this->payment?->paid_at !== null) {
            return 'paid';
        }

        if ($this->payment?->expired_at?->lessThanOrEqualTo(now()) ?? false) {
            return 'expired';
        }

        return 'pending';
    }

    public function isPaymentPending(): bool
    {
        return $this->payment !== null
            && filled($this->payment->transaction_id)
            && filled($this->payment->order_id)
            && $this->paymentStatus() === 'pending';
    }

    public function canSubmitPayment(): bool
    {
        return $this->payment === null || blank($this->payment->transaction_id);
    }

    public function paymentDestinationUrl(): ?string
    {
        $destination = $this->payment?->account_number;

        if (! is_string($destination) || filter_var($destination, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = parse_url($destination, PHP_URL_SCHEME);

        return is_string($scheme) && strtolower($scheme) === 'https' ? $destination : null;
    }

    public function qrisDataUri(): ?string
    {
        $payload = $this->payment?->account_number;

        if (
            $this->payment?->payment_type !== 'qris'
            || ! is_string($payload)
            || blank($payload)
            || filter_var($payload, FILTER_VALIDATE_URL) !== false
        ) {
            return null;
        }

        try {
            $writer = new Writer(new ImageRenderer(
                new RendererStyle(256, 2),
                new SvgImageBackEnd,
            ));

            return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($payload));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function loadOrder(): void
    {
        $this->order->refresh()->load(
            'orderShops.items.productFlat.media',
            'orderShops.shop',
            'orderShops.latestShipment',
            'latestPayment',
        );

        $this->payment = $this->order->latestPayment;
        $this->isPaid = $this->payment?->paid_at !== null;
    }

    private function loadPaymentMethods(
        PaymentGatewayManager $paymentGatewayManager,
        PaymentFeeCalculator $paymentFeeCalculator,
    ): void {
        try {
            $amount = (int) round((float) $this->order->total);

            $this->paymentMethods = collect($paymentGatewayManager->driver()->paymentMethods())
                ->map(fn (PaymentMethodData $method): array => $this->normalizePaymentMethod(
                    $method,
                    $amount,
                    $paymentFeeCalculator,
                ))
                ->values()
                ->all();
        } catch (Throwable $exception) {
            $this->paymentMethods = [];
            report($exception);
            $this->dispatchProviderError();
        }
    }

    private function loadPersistedPaymentMethod(
        PaymentGatewayManager $paymentGatewayManager,
        PaymentFeeCalculator $paymentFeeCalculator,
    ): void {
        try {
            $driver = PaymentGatewayDriver::tryFrom((string) $this->payment?->driver);

            if ($driver === null) {
                throw new InvalidArgumentException('Unsupported stored payment gateway.');
            }

            $providerCode = (string) $this->payment?->channel;
            $amount = (int) round((float) $this->payment?->amount);
            $method = collect($paymentGatewayManager->driver($driver)->paymentMethods())
                ->first(fn (PaymentMethodData $method): bool => strcasecmp($method->providerCode, $providerCode) === 0);

            if (! $method instanceof PaymentMethodData) {
                throw new InvalidArgumentException('Stored payment method is no longer available.');
            }

            $this->paymentMethods = [$this->normalizePaymentMethod($method, $amount, $paymentFeeCalculator)];
            $this->paymentMethod = $method->providerCode;
        } catch (Throwable $exception) {
            $this->paymentMethods = [];
            report($exception);
            $this->dispatchProviderError();
        }
    }

    private function normalizePaymentMethod(
        PaymentMethodData $method,
        int $amount,
        PaymentFeeCalculator $paymentFeeCalculator,
    ): array {
        $estimatedFee = $paymentFeeCalculator->calculateCustomerFee($method, $amount);
        $isAvailable = ($method->minimumAmount === null || $amount >= $method->minimumAmount)
            && ($method->maximumAmount === null || $amount <= $method->maximumAmount);

        return [
            'method' => $method->paymentMethod->value,
            'providerCode' => $method->providerCode,
            'name' => $method->name,
            'type' => $method->type,
            'estimatedFee' => $estimatedFee,
            'estimatedTotal' => $amount + $estimatedFee,
            'minimumAmount' => $method->minimumAmount,
            'maximumAmount' => $method->maximumAmount,
            'isAvailable' => $isAvailable,
            'icon' => $this->paymentMethodIcon($method),
        ];
    }

    private function isLocallyExpiredPayment(): bool
    {
        return $this->payment !== null
            && filled($this->payment->transaction_id)
            && filled($this->payment->order_id)
            && $this->payment->paid_at === null
            && ($this->payment->expired_at?->lessThanOrEqualTo(now()) ?? false);
    }

    private function paymentMethodIcon(PaymentMethodData $method): ?string
    {
        $identity = strtoupper($method->providerCode.' '.$method->name);

        foreach (['QRIS', 'CIMB', 'BSI', 'MANDIRI', 'PERMATA', 'BCA', 'BNI', 'BRI'] as $brand) {
            if (str_contains($identity, $brand)) {
                return 'payment-icon/'.$brand.'.svg';
            }
        }

        return null;
    }

    private function dispatchProviderError(): void
    {
        $this->dispatch(
            'toast',
            type: 'error',
            message: 'Pembayaran belum dapat diproses. Silakan coba lagi.',
        );
    }
};
