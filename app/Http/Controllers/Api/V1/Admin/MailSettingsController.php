<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTestEmailRequest;
use App\Mail\RenderedMail;
use App\Services\Mail\EmailDelivery;
use App\Services\Settings\SettingsService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

/** FR-E5: "send test email" for the mail credentials saved in the settings. */
class MailSettingsController extends Controller
{
    public function test(SendTestEmailRequest $request, EmailDelivery $delivery, SettingsService $settings): JsonResponse
    {
        $body = __('api.mail.test_body');
        $mail = new RenderedMail(
            __('api.mail.test_subject', ['platform' => $settings->get('general.platform_name')]),
            '<p>'.e($body).'</p>',
            $body,
        );

        try {
            $delivery->send($request->validated('to'), $mail);
        } catch (Throwable $e) {
            report($e);
            throw new ApiException(__('api.mail.test_failed', ['error' => $e->getMessage()]), 502, 'mail_test_failed');
        }

        return ApiResponse::success(['sent' => true]);
    }
}
