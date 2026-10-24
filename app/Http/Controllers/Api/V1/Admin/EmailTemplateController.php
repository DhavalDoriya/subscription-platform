<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListEmailTemplatesRequest;
use App\Http\Requests\Admin\PreviewEmailTemplateRequest;
use App\Http\Requests\Admin\SendTestEmailRequest;
use App\Http\Requests\Admin\UpdateEmailTemplateRequest;
use App\Http\Resources\Admin\EmailLogResource;
use App\Http\Resources\Admin\EmailTemplateResource;
use App\Http\Resources\Admin\EmailTemplateVersionResource;
use App\Models\EmailTemplate;
use App\Services\Mail\DatabaseTemplateMail;
use App\Services\Mail\TemplateCatalog;
use App\Services\Mail\TemplateEditor;
use App\Services\Mail\TemplateRenderer;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FR-AD8: the admin email template manager (FR-E1, FR-E2). */
class EmailTemplateController extends Controller
{
    public function index(ListEmailTemplatesRequest $request): JsonResponse
    {
        $templates = EmailTemplate::query()
            ->when($request->search(), function ($query, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q->whereRaw('lower("key") like ?', [$like])->orWhereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(subject) like ?', [$like]));
            })
            ->orderBy($request->sortColumn(ListEmailTemplatesRequest::SORTABLE, 'key'), $request->sortDirection())
            ->paginate($request->perPage());

        return ApiResponse::paginated($templates, EmailTemplateResource::class);
    }

    public function show(EmailTemplate $template): JsonResponse
    {
        return ApiResponse::success(new EmailTemplateResource($template));
    }

    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $template, TemplateEditor $editor): JsonResponse
    {
        $template = $editor->update($template, $request->validated(), $request->user('api'));

        activity()->causedBy($request->user('api'))->performedOn($template)->withProperties(['version' => $template->version, 'enabled' => $template->enabled])->log('email_template.updated');

        return ApiResponse::success(new EmailTemplateResource($template));
    }

    public function versions(EmailTemplate $template): JsonResponse
    {
        return ApiResponse::success(EmailTemplateVersionResource::collection($template->versions()->with('author')->get()));
    }

    public function restore(Request $request, EmailTemplate $template, int $version, TemplateEditor $editor): JsonResponse
    {
        $template = $editor->restore($template, $version, $request->user('api'));

        activity()->causedBy($request->user('api'))->performedOn($template)->withProperties(['restored_from' => $version, 'version' => $template->version])->log('email_template.restored');

        return ApiResponse::success(new EmailTemplateResource($template));
    }

    /** Renders unsaved content with the sample values, so the editor can show a live preview. */
    public function preview(PreviewEmailTemplateRequest $request, EmailTemplate $template, TemplateRenderer $renderer, TemplateCatalog $catalog): JsonResponse
    {
        $rendered = $renderer->renderContent(
            $template->key,
            $request->validated('subject') ?? $template->subject,
            $request->validated('html_body') ?? $template->html_body,
            $request->validated('text_body') ?? $template->text_body,
            $catalog->samples($template->key),
        );

        return ApiResponse::success($rendered);
    }

    /** Sends the saved template with sample values to one address and returns the delivery log entry. */
    public function test(SendTestEmailRequest $request, EmailTemplate $template, DatabaseTemplateMail $mail): JsonResponse
    {
        $log = $mail->sendNow($template, $request->validated('to'));

        return ApiResponse::success(new EmailLogResource($log));
    }
}
