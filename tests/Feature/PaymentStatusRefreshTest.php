<?php

use App\Actions\Ecommerce\Payment\ReconcilePaymentStatusAction;
use App\Actions\Ecommerce\Payment\RefreshPaymentStatusAction;
use App\Actions\Ecommerce\Payment\SendPaymentNotificationAction;
use App\Data\Payments\PaymentTransactionData;
use App\Enums\PaymentGatewayDriver;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Mail\OrderPaid;
use App\Mail\OrderPaymentFailed;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set([
        'midtrans.server_key' => 'midtrans-server-key',
        'midtrans.client_key' => 'midtrans-client-key',
        'midtrans.is_production' => false,
        'midtrans.retries' => 0,
        'payment.drivers.paywuz.api_key' => 'paywuz-api-key',
        'payment.drivers.paywuz.base_url' => 'https://api.paywuz.id/v1',
    ]);
});

it('refreshes an existing payment through its stored driver instead of the configured default', function (
    PaymentGatewayDriver $storedDriver,
    PaymentGatewayDriver $defaultDriver,
    string $expectedUrl,
    array $providerResponse,
) {
    config(['payment.default' => $defaultDriver->value]);

    $order = Order::factory()->create(['total' => 118_000]);
    $providerTransactionId = $storedDriver === PaymentGatewayDriver::Midtrans
        ? $providerResponse['transaction_id']
        : data_get($providerResponse, 'data.id');
    $payment = Payment::factory()->create([
        'driver' => $storedDriver->value,
        'payable_type' => Order::class,
        'payable_id' => $order->getKey(),
        'order_id' => 'stored-driver-order',
        'transaction_id' => $providerTransactionId,
        'paid_at' => null,
        'expired_at' => now()->addDay(),
        'channel' => 'bca',
        'amount' => 118_000,
        'fee' => 0,
        'total' => 118_000,
    ]);
    Mail::fake();

    $transactionLevels = [];

    Http::fake(function (Request $request) use (&$transactionLevels, $expectedUrl, $providerResponse) {
        $transactionLevels[] = DB::transactionLevel();

        expect($request->url())->toBe($expectedUrl);

        return Http::response($providerResponse);
    });

    $result = app(RefreshPaymentStatusAction::class)->handle($payment);

    expect($transactionLevels)->toBe([0])
        ->and($result->is($payment))->toBeTrue()
        ->and($payment->refresh()->paid_at)->not->toBeNull()
        ->and($order->refresh()->status)->toBeTrue();

    Http::assertSentCount(1);
    Mail::assertSent(OrderPaid::class, 1);
    Mail::assertNotSent(OrderPaymentFailed::class);
})->with([
    'stored Midtrans while default is Paywuz' => [
        PaymentGatewayDriver::Midtrans,
        PaymentGatewayDriver::Paywuz,
        'https://api.sandbox.midtrans.com/v2/stored-driver-order/status',
        [
            'transaction_id' => 'midtrans-status-id',
            'order_id' => 'stored-driver-order',
            'gross_amount' => '118000.00',
            'payment_type' => 'bank_transfer',
            'transaction_status' => 'settlement',
            'va_numbers' => [
                ['bank' => 'bca', 'va_number' => '880812345678'],
            ],
        ],
    ],
    'stored Paywuz while default is Midtrans' => [
        PaymentGatewayDriver::Paywuz,
        PaymentGatewayDriver::Midtrans,
        'https://api.paywuz.id/v1/transactions/stored-driver-order',
        [
            'data' => [
                'id' => 'paywuz-status-id',
                'orderId' => 'stored-driver-order',
                'amount' => 118_000,
                'totalPayment' => 118_000,
                'paymentMethod' => 'BCAVA',
                'paymentNumber' => '880812345678',
                'paymentUrl' => null,
                'status' => 'success',
                'expiresAt' => '2026-09-15T04:30:00.000Z',
                'createdAt' => '2026-09-14T04:30:00.000Z',
            ],
        ],
    ],
]);

it('rejects a mismatched provider order ID without changing payment state', function () {
    config(['payment.default' => PaymentGatewayDriver::Midtrans->value]);

    $order = Order::factory()->create();
    $payment = Payment::factory()->create([
        'driver' => PaymentGatewayDriver::Paywuz->value,
        'payable_type' => Order::class,
        'payable_id' => $order->getKey(),
        'order_id' => 'expected-order-id',
        'paid_at' => null,
        'expired_at' => now()->addDay(),
    ]);
    $originalExpiry = $payment->expired_at->copy();
    Mail::fake();

    Http::fake([
        'https://api.paywuz.id/v1/transactions/expected-order-id' => Http::response([
            'data' => [
                'id' => 'paywuz-status-id',
                'orderId' => 'different-order-id',
                'amount' => 113_500,
                'totalPayment' => 118_000,
                'paymentMethod' => 'BCAVA',
                'paymentNumber' => '880812345678',
                'paymentUrl' => null,
                'status' => 'success',
                'expiresAt' => '2026-09-15T04:30:00.000Z',
                'createdAt' => '2026-09-14T04:30:00.000Z',
            ],
        ]),
    ]);

    expect(fn () => app(RefreshPaymentStatusAction::class)->handle($payment))
        ->toThrow(UnexpectedValueException::class, 'Payment gateway returned a mismatched payment status response.');

    expect($payment->refresh()->paid_at)->toBeNull()
        ->and($payment->expired_at->equalTo($originalExpiry))->toBeTrue()
        ->and($order->refresh()->status)->toBeFalse();

    Http::assertSentCount(1);
    Mail::assertNothingSent();
});

it('rejects authoritative status integrity mismatches without changing payment or order', function (string $mismatch) {
    $order = Order::factory()->create([
        'total' => $mismatch === 'principal' ? 120_000 : 113_500,
        'payment_fee' => 4_500,
    ]);
    $payment = Payment::factory()->create([
        'driver' => PaymentGatewayDriver::Paywuz->value,
        'payable_type' => Order::class,
        'payable_id' => $order->getKey(),
        'order_id' => 'integrity-mismatch-order',
        'transaction_id' => 'expected-transaction-id',
        'channel' => 'BCAVA',
        'amount' => 113_500,
        'fee' => 4_500,
        'total' => 118_000,
        'paid_at' => null,
        'expired_at' => now()->addDay(),
    ]);
    $originalExpiry = $payment->expired_at->copy();
    Mail::fake();

    Http::fake([
        'https://api.paywuz.id/v1/transactions/integrity-mismatch-order' => Http::response([
            'data' => [
                'id' => $mismatch === 'transaction' ? 'conflicting-transaction-id' : 'expected-transaction-id',
                'orderId' => 'integrity-mismatch-order',
                'amount' => $mismatch === 'amount' ? 113_499 : 113_500,
                'totalPayment' => $mismatch === 'total' ? 118_001 : 118_000,
                'paymentMethod' => $mismatch === 'method' ? 'BNIVA' : 'BCAVA',
                'paymentNumber' => '880812345678',
                'paymentUrl' => null,
                'status' => 'success',
                'expiresAt' => '2026-09-15T04:30:00.000Z',
                'createdAt' => '2026-09-14T04:30:00.000Z',
            ],
        ]),
    ]);

    expect(fn () => app(RefreshPaymentStatusAction::class)->handle($payment))
        ->toThrow(UnexpectedValueException::class);

    $payment->refresh();
    $order->refresh();

    expect($payment->transaction_id)->toBe('expected-transaction-id')
        ->and((float) $payment->amount)->toBe(113_500.0)
        ->and((float) $payment->fee)->toBe(4_500.0)
        ->and((float) $payment->total)->toBe(118_000.0)
        ->and($payment->paid_at)->toBeNull()
        ->and($payment->expired_at->equalTo($originalExpiry))->toBeTrue()
        ->and((float) $order->payment_fee)->toBe(4_500.0)
        ->and($order->status)->toBeFalse();

    Mail::assertNothingSent();
})->with(['transaction', 'amount', 'total', 'method', 'principal']);

it('allows an authoritative paid status to win after a stale failed state', function () {
    $order = Order::factory()->create(['total' => 113_500]);
    $payment = Payment::factory()->create([
        'driver' => PaymentGatewayDriver::Paywuz->value,
        'payable_type' => Order::class,
        'payable_id' => $order->getKey(),
        'order_id' => 'paid-wins-order',
        'transaction_id' => 'paid-wins-transaction',
        'channel' => 'BCAVA',
        'amount' => 113_500,
        'fee' => 4_500,
        'total' => 118_000,
        'paid_at' => null,
        'expired_at' => now()->subMinute(),
    ]);
    Mail::fake();

    Http::fake([
        'https://api.paywuz.id/v1/transactions/paid-wins-order' => Http::response([
            'data' => [
                'id' => 'paid-wins-transaction',
                'orderId' => 'paid-wins-order',
                'amount' => 113_500,
                'totalPayment' => 118_000,
                'paymentMethod' => 'BCAVA',
                'paymentNumber' => '880812345678',
                'paymentUrl' => null,
                'status' => 'success',
                'expiresAt' => '2026-09-15T04:30:00.000Z',
                'createdAt' => '2026-09-14T04:30:00.000Z',
            ],
        ]),
    ]);

    app(RefreshPaymentStatusAction::class)->handle($payment);

    expect($payment->refresh()->paid_at)->not->toBeNull()
        ->and($order->refresh()->status)->toBeTrue();

    Mail::assertSent(OrderPaid::class, 1);
});

it('prevents provider terminal states from regressing to pending while allowing paid authority', function (
    PaymentStatus $terminalStatus,
) {
    $order = Order::factory()->create([
        'total' => 100_000,
        'payment_fee' => 3_400,
    ]);
    $payment = Payment::factory()->create([
        'driver' => PaymentGatewayDriver::Paywuz->value,
        'payable_type' => Order::class,
        'payable_id' => $order->getKey(),
        'order_id' => 'terminal-transition-order',
        'transaction_id' => 'terminal-transition-transaction',
        'payment_type' => 'virtual_account',
        'channel' => 'CIMBVA',
        'account_number' => 'original-destination',
        'account_code' => null,
        'amount' => 100_000,
        'fee' => 3_400,
        'total' => 103_400,
        'paid_at' => null,
        'expired_at' => now()->addDay(),
    ]);
    Mail::fake();
    $action = new ReconcilePaymentStatusAction;

    $terminalPayment = $action->handle($payment, new PaymentTransactionData(
        id: 'terminal-transition-transaction',
        orderId: 'terminal-transition-order',
        amount: 100_000,
        totalPayment: 103_400,
        paymentMethod: PaymentMethod::Va,
        paymentNumber: null,
        paymentUrl: null,
        status: $terminalStatus,
        providerCode: 'CIMBVA',
    ));
    $terminalExpiry = $terminalPayment->expired_at?->copy();

    expect($terminalPayment->account_code)->toBe('provider-status:'.$terminalStatus->value)
        ->and($terminalExpiry?->lessThanOrEqualTo(now()))->toBeTrue()
        ->and($terminalPayment->account_number)->toBe('original-destination')
        ->and($terminalPayment->paid_at)->toBeNull();

    $pendingPayment = $action->handle($terminalPayment, new PaymentTransactionData(
        id: 'terminal-transition-transaction',
        orderId: 'terminal-transition-order',
        amount: 100_000,
        totalPayment: 103_400,
        paymentMethod: PaymentMethod::Va,
        paymentNumber: 'new-pending-destination',
        paymentUrl: null,
        status: PaymentStatus::Pending,
        expiresAt: now()->addDays(2)->toImmutable(),
        providerCode: 'CIMBVA',
    ));

    expect($pendingPayment->account_code)->toBe('provider-status:'.$terminalStatus->value)
        ->and($pendingPayment->expired_at?->equalTo($terminalExpiry))->toBeTrue()
        ->and($pendingPayment->account_number)->toBe('original-destination')
        ->and($pendingPayment->paid_at)->toBeNull()
        ->and($order->refresh()->status)->toBeFalse();

    $paidPayment = $action->handle($pendingPayment, new PaymentTransactionData(
        id: 'terminal-transition-transaction',
        orderId: 'terminal-transition-order',
        amount: 100_000,
        totalPayment: 103_400,
        paymentMethod: PaymentMethod::Va,
        paymentNumber: null,
        paymentUrl: null,
        status: PaymentStatus::Paid,
        providerCode: 'CIMBVA',
    ));

    expect($paidPayment->paid_at)->not->toBeNull()
        ->and($paidPayment->account_code)->toBeNull()
        ->and($order->refresh()->status)->toBeTrue();

    Mail::assertSent(OrderPaymentFailed::class, 1);
    Mail::assertSent(OrderPaid::class, 1);
})->with([
    'failed' => PaymentStatus::Failed,
    'cancelled' => PaymentStatus::Cancelled,
    'expired' => PaymentStatus::Expired,
]);

it('suppresses a failure notification if the order becomes paid before the transaction commits', function (
    PaymentStatus $terminalStatus,
) {
    $order = Order::factory()->create();
    $payment = Payment::factory()->create([
        'payable_type' => Order::class,
        'payable_id' => $order->getKey(),
        'account_code' => 'provider-status:'.$terminalStatus->value,
        'paid_at' => null,
    ]);
    Mail::fake();

    DB::transaction(function () use ($payment, $order): void {
        app(SendPaymentNotificationAction::class)->handle($payment, $order);

        Mail::assertNothingSent();
        $order->update(['status' => true]);
    });

    expect($order->refresh()->status)->toBeTrue();
    Mail::assertNotSent(OrderPaymentFailed::class);
})->with([
    'failed' => PaymentStatus::Failed,
    'cancelled' => PaymentStatus::Cancelled,
    'expired' => PaymentStatus::Expired,
]);

it('preserves payment state when a provider status request fails', function (
    PaymentGatewayDriver $storedDriver,
    string $providerUrl,
    string $exceptionClass,
) {
    config(['payment.default' => $storedDriver === PaymentGatewayDriver::Midtrans
        ? PaymentGatewayDriver::Paywuz->value
        : PaymentGatewayDriver::Midtrans->value]);

    $order = Order::factory()->create();
    $payment = Payment::factory()->create([
        'driver' => $storedDriver->value,
        'payable_type' => Order::class,
        'payable_id' => $order->getKey(),
        'order_id' => 'provider-failure-order',
        'paid_at' => null,
        'expired_at' => now()->addDay(),
    ]);
    $originalExpiry = $payment->expired_at->copy();
    Mail::fake();

    Http::fake([
        $providerUrl => Http::response(['message' => 'Unavailable'], 503),
    ]);

    expect(fn () => app(RefreshPaymentStatusAction::class)->handle($payment))
        ->toThrow($exceptionClass);

    expect($payment->refresh()->paid_at)->toBeNull()
        ->and($payment->expired_at->equalTo($originalExpiry))->toBeTrue()
        ->and($order->refresh()->status)->toBeFalse();

    Http::assertSentCount(1);
    Mail::assertNothingSent();
})->with([
    'Midtrans failure' => [
        PaymentGatewayDriver::Midtrans,
        'https://api.sandbox.midtrans.com/v2/provider-failure-order/status',
        RuntimeException::class,
    ],
    'Paywuz failure' => [
        PaymentGatewayDriver::Paywuz,
        'https://api.paywuz.id/v1/transactions/provider-failure-order',
        RequestException::class,
    ],
]);

it('preserves payment state for an unknown provider status', function () {
    $order = Order::factory()->create();
    $payment = Payment::factory()->create([
        'driver' => PaymentGatewayDriver::Paywuz->value,
        'payable_type' => Order::class,
        'payable_id' => $order->getKey(),
        'order_id' => 'unknown-provider-status',
        'paid_at' => null,
        'expired_at' => now()->addDay(),
    ]);
    $originalExpiry = $payment->expired_at->copy();
    Mail::fake();

    Http::fake([
        'https://api.paywuz.id/v1/transactions/unknown-provider-status' => Http::response([
            'data' => [
                'id' => 'paywuz-status-id',
                'orderId' => 'unknown-provider-status',
                'amount' => 113_500,
                'totalPayment' => 118_000,
                'paymentMethod' => 'BCAVA',
                'paymentNumber' => '880812345678',
                'paymentUrl' => null,
                'status' => 'reviewing',
                'expiresAt' => '2026-09-15T04:30:00.000Z',
                'createdAt' => '2026-09-14T04:30:00.000Z',
            ],
        ]),
    ]);

    expect(fn () => app(RefreshPaymentStatusAction::class)->handle($payment))
        ->toThrow(UnexpectedValueException::class, 'Paywuz returned an invalid payment response.');

    expect($payment->refresh()->paid_at)->toBeNull()
        ->and($payment->expired_at->equalTo($originalExpiry))->toBeTrue()
        ->and($order->refresh()->status)->toBeFalse();

    Http::assertSentCount(1);
    Mail::assertNothingSent();
});
