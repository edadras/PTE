<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Domain\Commerce\Data\PaymentRequest;
use App\Domain\Commerce\Enums\PaymentGatewayKey;
use App\Domain\Commerce\Gateways\NextPayGateway;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Services\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NextPay v2 REST driver — docs/09 §4.
 */
final class NextPayGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://nextpay.org/nx/gateway/token';

    private const VERIFY_URL = 'https://nextpay.org/nx/gateway/verify';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nextpay', [
            'api_key' => 'np_test_key',
            'callback_url' => 'https://platform.test/billing/callback/nextpay',
        ]);
    }

    #[Test]
    public function the_manager_resolves_the_driver(): void
    {
        $driver = app(PaymentGatewayManager::class)->driver('nextpay');

        $this->assertInstanceOf(NextPayGateway::class, $driver);
        $this->assertTrue(PaymentGatewayKey::NextPay->isImplemented());
        $this->assertTrue($driver->isConfigured());

        config()->set('services.nextpay.api_key', null);
        $this->assertFalse($driver->isConfigured());
    }

    #[Test]
    public function create_payment_requests_a_token_and_returns_the_redirect(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['code' => -1, 'trans_id' => 'np-trans-1']),
        ]);

        $session = $this->gateway()->createPayment(new PaymentRequest(
            academyId: 1,
            amount: 2_500_000,
            description: 'Pro plan',
            payerMobile: '09120000000',
        ));

        $this->assertSame('np-trans-1', $session->reference);
        $this->assertSame('https://nextpay.org/nx/gateway/payment/np-trans-1', $session->redirectUrl);

        Http::assertSent(static fn (Request $request): bool => $request->url() === self::TOKEN_URL
            && $request['api_key'] === 'np_test_key'
            && $request['amount'] === 2_500_000
            && $request['currency'] === 'IRR'
            && $request['callback_uri'] === 'https://platform.test/billing/callback/nextpay');
    }

    #[Test]
    public function verify_resends_the_stored_amount_and_marks_the_payment_paid(): void
    {
        $payment = $this->payment('np-trans-1', 2_500_000);

        Http::fake([
            self::VERIFY_URL => Http::response([
                'code' => 0,
                'amount' => 2_500_000,
                'Shaparak_Ref_Id' => 'SH-123',
                'card_holder' => '6037****1234',
            ]),
        ]);

        $result = $this->gateway()->verify('np-trans-1');

        $this->assertTrue($result->successful);
        $this->assertSame(2_500_000, $result->amount);
        $this->assertSame('SH-123', $result->gatewayRef);
        $this->assertSame('6037****1234', $result->cardMask);

        // The redirect is never trusted: the stored amount goes back out.
        Http::assertSent(static fn (Request $request): bool => $request->url() === self::VERIFY_URL
            && $request['trans_id'] === 'np-trans-1'
            && $request['amount'] === (int) $payment->amount);
    }

    #[Test]
    public function verify_rejects_an_amount_the_gateway_does_not_confirm(): void
    {
        $this->payment('np-trans-1', 2_500_000);

        Http::fake([
            self::VERIFY_URL => Http::response([
                'code' => 0,
                'amount' => 990,
            ]),
        ]);

        $result = $this->gateway()->verify('np-trans-1');

        $this->assertFalse($result->successful);
        $this->assertSame('amount_mismatch', $result->errorCode);
    }

    #[Test]
    public function verify_refuses_a_reference_no_payment_was_stored_for(): void
    {
        Http::fake();

        $result = $this->gateway()->verify('never-created');

        $this->assertFalse($result->successful);
        $this->assertSame('unknown_reference', $result->errorCode);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_non_zero_verify_code_is_a_failure(): void
    {
        $this->payment('np-trans-1', 2_500_000);

        Http::fake([
            self::VERIFY_URL => Http::response(['code' => -2]),
        ]);

        $result = $this->gateway()->verify('np-trans-1');

        $this->assertFalse($result->successful);
        $this->assertSame('-2', $result->errorCode);
    }

    #[Test]
    public function refund_goes_through_the_verify_endpoint_with_refund_request(): void
    {
        $payment = $this->payment('np-trans-1', 2_500_000);

        Http::fake([
            self::VERIFY_URL => Http::response(['code' => -90]),
        ]);

        $result = $this->gateway()->refund($payment);

        $this->assertTrue($result->successful);
        $this->assertSame(2_500_000, $result->amount);

        Http::assertSent(static fn (Request $request): bool => $request->url() === self::VERIFY_URL
            && $request['refund_request'] === 'yes_money_back'
            && $request['trans_id'] === 'np-trans-1');
    }

    #[Test]
    public function a_partial_refund_is_refused_rather_than_faked(): void
    {
        Http::fake();

        $result = $this->gateway()->refund($this->payment('np-trans-1', 2_500_000), 1_000);

        $this->assertFalse($result->successful);
        $this->assertSame('partial_unsupported', $result->errorCode);
        Http::assertNothingSent();
    }

    private function gateway(): NextPayGateway
    {
        return new NextPayGateway;
    }

    private function payment(string $transId, int $amount): Payment
    {
        return Payment::factory()->create([
            'gateway' => PaymentGatewayKey::NextPay,
            'authority' => $transId,
            'amount' => $amount,
        ]);
    }
}
