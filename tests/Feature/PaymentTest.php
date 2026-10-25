<?php

namespace Tests\Feature;

use App\Enums\HttpStatus;
use App\Models\TempUser;
use App\Models\User;
use App\Traits\ControllerCanExtend;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use ControllerCanExtend;
    // use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped('all tests in this file are invactive for this server configuration!');
    }

    public function test_temp_user_can_initialize_payment(): void
    {
        $request = new FormRequest;
        $user = TempUser::factory()->create();

        $pay = $this->payWith('paystack')->makePayment($request, $user, 1000);
        $this->assertEquals(HttpStatus::OK, $pay['status_code'] ?? null);
    }

    public function test_user_can_initialize_payment(): void
    {
        $request = new FormRequest;
        $user = User::factory()->create();

        $pay = $this->payWith('paystack')->makePayment($request, $user, 1000);
        $this->assertEquals(HttpStatus::OK, $pay['status_code'] ?? null);
    }

    public function test_user_can_initialize_and_verify_payment(): void
    {
        $request = new FormRequest;
        $user = User::factory()->create();

        $pay = $this->payWith('paystack')->makePayment($request, $user, 1000);
        $verify = $this->payWith('paystack')->verifyPayment($request, $user, $pay['reference'] ?? null);
        $this->assertEquals(HttpStatus::OK, $verify['status_code'] ?? null);
    }

    public function test_temp_user_can_initialize_and_verify_payment(): void
    {
        $request = new FormRequest;
        $user = TempUser::factory()->create();

        $pay = $this->payWith('paystack')->makePayment($request, $user, 1000);
        $verify = $this->payWith('paystack')->verifyPayment($request, $user, $pay['reference'] ?? null);
        $this->assertEquals(HttpStatus::OK, $verify['status_code'] ?? null);
    }
}
