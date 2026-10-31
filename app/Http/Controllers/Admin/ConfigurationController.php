<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Http\Resources\SettingsResource;
use App\Mail\TestMail;
use App\Services\Settings\AppConfiguration;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Lets admins change the general app settings: currency, creator share, payout limits and sign-up checks,
 * and send a test email.
 */
class ConfigurationController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-configuration'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to change the app configuration.'
            );

            return $next($request);
        });
    }

    public function show()
    {
        return $this->settings();
    }

    public function update(Request $request)
    {
        AppConfiguration::update($request->all());

        return $this->settings('Configuration saved.', HttpStatus::ACCEPTED);
    }

    /**
     * Sends a test email right away with the app's mailer and reports what the mailer said.
     */
    public function testMail(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $mailer = (string) config('mail.default');

        try {
            Mail::to($data['email'])->send(new TestMail($mailer));
        } catch (TransportExceptionInterface $e) {
            abort(HttpStatus::BAD_GATEWAY->value, 'The test email could not be sent: ' . $e->getMessage());
        }

        $result = ['email' => $data['email'], 'mailer' => $mailer];

        return (new ActionResource($result))
            ->additional([
                'message' => "Test email sent to {$data['email']}.",
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    protected function settings(string $message = 'OK', HttpStatus $status = HttpStatus::OK)
    {
        $settings = [
            'fields' => AppConfiguration::forAdmin(),
            'mail' => [
                'mailer' => config('mail.default'),
                'from' => config('mail.from.address'),
            ],
        ];

        return (new SettingsResource($settings))
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => $status,
            ])
            ->response()
            ->setStatusCode($status->value);
    }
}
