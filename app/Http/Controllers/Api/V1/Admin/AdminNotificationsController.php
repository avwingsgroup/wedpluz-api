<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Mail\TemplatedMail;
use App\Models\NotificationCampaign;
use App\Models\NotificationTemplate;
use App\Services\Admin\AdminPresenter;
use App\Services\Admin\AuditLogger;
use App\Services\Notifications\CampaignService;
use App\Services\Notifications\TemplateRenderer;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AdminNotificationsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly CampaignService $campaigns) {}

    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = NotificationCampaign::query()->with('createdBy')
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where('name', 'ilike', $p));
        $list->applySort($query, ['createdAt' => 'created_at', 'scheduledAt' => 'scheduled_at', 'name' => 'name'], 'createdAt');

        return $this->paginated($list->paginate($query), fn (NotificationCampaign $c) => AdminPresenter::campaign($c));
    }

    public function show(string $id): JsonResponse
    {
        $campaign = NotificationCampaign::query()->with('createdBy')->findOrFail($id);
        $byChannel = DB::table('notification_deliveries')->where('campaign_id', $campaign->id)->groupBy('channel')
            ->selectRaw("channel, count(*) filter (where status in ('sent','delivered','opened','clicked','read')) sent, count(*) filter (where status in ('delivered','opened','clicked','read')) delivered, count(*) filter (where status in ('opened','clicked','read')) opened, count(*) filter (where status = 'failed') failed")
            ->get()->map(fn ($r) => ['channel' => $r->channel, 'sent' => (int) $r->sent, 'delivered' => (int) $r->delivered, 'opened' => (int) $r->opened, 'failed' => (int) $r->failed])->all();

        return $this->ok(AdminPresenter::campaign($campaign) + ['deliveryByChannel' => $byChannel]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'channels' => ['required', 'array', 'min:1'], 'channels.*' => ['in:push,email,sms,whatsapp,in_app'],
            'audience' => ['sometimes', 'array'], 'audience.type' => ['sometimes', 'in:all,segment,event_attendees,organizers,vendors,custom'],
            'audience.filters' => ['sometimes', 'array'], 'audience.filters.*' => ['nullable', 'string', 'max:5000'],
            'templateId' => ['nullable', 'string', 'exists:notification_templates,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'scheduledAt' => ['nullable', 'date', 'after:now'],
        ]);
        $audience = ['type' => $data['audience']['type'] ?? 'all', 'filters' => $data['audience']['filters'] ?? []];
        $audience['estimatedSize'] = $this->campaigns->audience($audience)->count();

        $campaign = NotificationCampaign::query()->create([
            'name' => $data['name'], 'channels' => array_values(array_unique($data['channels'])), 'audience' => $audience,
            'template_id' => $data['templateId'] ?? null, 'subject' => $data['subject'] ?? null, 'body' => $data['body'],
            'status' => 'draft', 'scheduled_at' => $data['scheduledAt'] ?? null, 'stats' => ['sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'failed' => 0],
            'created_by_admin_id' => $this->admin()->id,
        ]);
        $this->audit->log('campaign.created', 'notifications', $campaign, $campaign->name, null, ['channels' => $campaign->channels, 'audience' => $audience]);

        return $this->created(AdminPresenter::campaign($campaign->load('createdBy')));
    }

    public function send(string $id): JsonResponse
    {
        $campaign = $this->campaigns->send(NotificationCampaign::query()->findOrFail($id));
        $this->audit->log('campaign.sent', 'notifications', $campaign, $campaign->name, null, ['status' => $campaign->status]);

        return $this->ok(AdminPresenter::campaign($campaign->load('createdBy')));
    }

    public function cancel(string $id): JsonResponse
    {
        $campaign = NotificationCampaign::query()->findOrFail($id);
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            throw ApiException::conflict('Only draft or scheduled campaigns can be cancelled.');
        }
        $campaign->forceFill(['status' => 'cancelled'])->save();
        $this->audit->log('campaign.cancelled', 'notifications', $campaign, $campaign->name);

        return $this->ok(AdminPresenter::campaign($campaign->load('createdBy')));
    }

    /** Sends a test to the signed-in admin's email (other channels need a customer device or phone). */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', 'in:email,push,sms,whatsapp,in_app'], 'body' => ['required', 'string', 'max:5000']]);
        if ($data['channel'] !== 'email') {
            throw ApiException::validation('Test sends to administrators are available by email.', ['channel' => ['Use the email channel for test sends.']]);
        }
        Mail::to($this->admin()->email)->queue(new TemplatedMail('[Test] Wedpluz campaign', '<p>'.nl2br(e($data['body'])).'</p>'));
        $this->audit->log('campaign.test_sent', 'notifications', null, $this->admin()->email, null, ['channel' => 'email']);

        return $this->ok(['sent' => true]);
    }

    public function templates(): JsonResponse
    {
        return $this->ok(NotificationTemplate::query()->orderBy('key')->orderBy('channel')->get()->map(fn ($t) => AdminPresenter::template($t))->all());
    }

    public function createTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'alpha_dash', 'max:60'], 'name' => ['required', 'string', 'max:200'],
            'channel' => ['required', 'in:push,email,sms,whatsapp,in_app'], 'subject' => ['nullable', 'string', 'max:255'], 'body' => ['required', 'string', 'max:20000'],
        ]);
        if (NotificationTemplate::query()->where('key', $data['key'])->where('channel', $data['channel'])->exists()) {
            throw ApiException::conflict('A template with this key already exists for this channel.');
        }
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', ($data['subject'] ?? '').' '.$data['body'], $m);
        $template = NotificationTemplate::query()->create($data + ['variables' => array_values(array_unique($m[1])), 'is_active' => true]);
        $this->audit->log('template.created', 'notifications', $template, $template->key.'/'.$template->channel);

        return $this->created(AdminPresenter::template($template));
    }

    public function updateTemplate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:200'], 'subject' => ['sometimes', 'nullable', 'string', 'max:255'], 'body' => ['sometimes', 'string', 'max:20000'], 'isActive' => ['sometimes', 'boolean']]);
        $template = NotificationTemplate::query()->findOrFail($id);
        $before = $template->only(['name', 'subject', 'body', 'is_active']);
        $changes = array_intersect_key($data, array_flip(['name', 'subject', 'body'])) + (array_key_exists('isActive', $data) ? ['is_active' => $data['isActive']] : []);
        if (isset($data['body']) || array_key_exists('subject', $data)) {
            preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', ($data['subject'] ?? $template->subject ?? '').' '.($data['body'] ?? $template->body), $m);
            $changes['variables'] = array_values(array_unique($m[1]));
        }
        $template->forceFill($changes)->save();
        TemplateRenderer::forget($template->key, $template->channel);
        $this->audit->log('template.updated', 'notifications', $template, $template->key.'/'.$template->channel, $before, $data);

        return $this->ok(AdminPresenter::template($template));
    }
}
