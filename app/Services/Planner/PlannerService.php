<?php

namespace App\Services\Planner;

use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Integrations\Ai\ImageGenerator;
use App\Integrations\Ai\PlannerAssistant;
use App\Integrations\Support\IntegrationException;
use App\Jobs\GenerateDesignJob;
use App\Models\HallLayout;
use App\Models\PlannerChat;
use App\Models\PlannerChatMessage;
use App\Models\PlannerGeneration;
use App\Models\PlannerMenu;
use App\Models\User;
use App\Services\Media\UploadService;
use App\Services\Platform\SettingsService;
use App\Services\Subscriptions\PlanGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pluz Planner (contract status: proposed). Menus and hall layouts are plain
 * CRUD and feed event food selection and seat maps. Chat and design
 * generation are plan-gated (Premium), behind feature flags, and need their
 * providers configured.
 */
class PlannerService
{
    public function __construct(
        private readonly PlannerAssistant $assistant,
        private readonly ImageGenerator $images,
        private readonly UploadService $uploads,
        private readonly SettingsService $settings,
        private readonly PlanGate $plans,
    ) {}

    /** @param array{content:string, attachmentUploadIds?:list<string>, intent?:?string} $data */
    public function sendMessage(PlannerChat $chat, User $user, array $data): array
    {
        if (! $this->settings->feature('plannerAiEnabled') || ! $this->assistant->isConfigured()) {
            throw ApiException::forbidden('The planning assistant is not available yet.');
        }
        $this->plans->require($user, 'planner_ai', 'The planning assistant is available on the Premium plan.');

        $attachments = [];
        foreach (array_values($data['attachmentUploadIds'] ?? []) as $i => $id) {
            $upload = $this->uploads->consume($id, $user, ['planner_reference'], "attachmentUploadIds.$i");
            $attachments[] = ['type' => $upload->mime_type === 'application/pdf' ? 'pdf' : 'image', 'url' => (string) $upload->url, 'name' => $upload->original_name ?: 'Attachment'];
        }

        $userMessage = PlannerChatMessage::query()->create([
            'chat_id' => $chat->id, 'role' => 'user', 'content' => $data['content'], 'attachments' => $attachments,
        ]);
        if ($chat->title === 'New chat') {
            $chat->forceFill(['title' => Str::limit(trim($data['content']), 60, '…')])->save();
        }

        $history = PlannerChatMessage::query()->where('chat_id', $chat->id)->orderByDesc('created_at')->orderByDesc('id')->limit(40)->get()->reverse()
            ->map(fn (PlannerChatMessage $m) => [
                'role' => $m->role,
                'content' => $m->content.($m->attachments ? "\n\n[Attached: ".implode(', ', array_column($m->attachments, 'name')).']' : ''),
            ])->values()->all();

        try {
            $reply = $this->assistant->reply($history, $data['intent'] ?? null);
        } catch (IntegrationException $e) {
            throw ApiException::forbidden($e->transient ? 'The assistant is busy right now. Please try again in a moment.' : 'The planning assistant is not available right now.');
        }

        $generation = null;
        if (in_array($data['intent'] ?? null, ['cake_design', 'event_branding'], true) && $this->images->isConfigured() && ! $reply['refused']) {
            $generation = $this->queueGeneration($user, $data['intent'], $data['content']);
        }

        $assistantMessage = PlannerChatMessage::query()->create([
            'chat_id' => $chat->id,
            'role' => 'assistant',
            'content' => $reply['content'],
            'attachments' => [],
            'generation_id' => $generation?->id,
            'input_tokens' => $reply['inputTokens'],
            'output_tokens' => $reply['outputTokens'],
        ]);
        $chat->touch();

        return [$userMessage, $assistantMessage->setRelation('generation', $generation)];
    }

    public function generate(User $user, string $type, string $prompt): PlannerGeneration
    {
        if (! $this->settings->feature('plannerAiEnabled') || ! $this->images->isConfigured()) {
            throw ApiException::forbidden('Design generation is not available yet.');
        }
        $this->plans->require($user, 'planner_generation', 'Design generation is available on the Premium plan.');

        return $this->queueGeneration($user, $type, $prompt);
    }

    private function queueGeneration(User $user, string $type, string $prompt): PlannerGeneration
    {
        $generation = PlannerGeneration::query()->create([
            'user_id' => $user->id, 'type' => $type, 'prompt' => mb_substr($prompt, 0, 4000),
            'name' => Str::limit($prompt, 60, '…'), 'status' => 'queued', 'result_urls' => [],
        ]);
        GenerateDesignJob::dispatch($generation->id);

        return $generation;
    }

    /** @param array<string,mixed> $data */
    public function saveMenu(User $user, ?PlannerMenu $menu, array $data): PlannerMenu
    {
        $sections = array_map(fn ($s) => [
            'id' => (string) ($s['id'] ?? Str::ulid()),
            'title' => (string) ($s['title'] ?? 'Menu'),
            'items' => array_map(fn ($i) => [
                'id' => (string) ($i['id'] ?? Str::ulid()),
                'name' => (string) $i['name'],
                'description' => $i['description'] ?? null,
                'price' => (int) ($i['price'] ?? 0),
                'imageUrl' => $i['imageUrl'] ?? null,
            ], $s['items'] ?? []),
        ], $data['sections'] ?? ($menu?->sections ?? []));

        $menu ??= new PlannerMenu(['user_id' => $user->id]);
        $menu->forceFill([
            'name' => $data['name'] ?? $menu->name ?? 'Untitled menu',
            'sections' => $sections,
            'currency' => $data['currency'] ?? $menu->currency ?? 'NGN',
        ])->save();

        return $menu;
    }

    /** @param array<string,mixed> $data */
    public function saveLayout(User $user, ?HallLayout $layout, array $data): HallLayout
    {
        $tables = $data['tables'] ?? ($layout?->tables ?? []);
        $seatIds = [];
        foreach ($tables as $t => $table) {
            foreach ($table['seats'] ?? [] as $s => $seat) {
                $id = (string) ($seat['id'] ?? '');
                if ($id === '' || isset($seatIds[$id])) {
                    throw ApiException::validation('Every seat needs a unique id.', ["tables.$t.seats.$s.id" => ['Seat ids must be unique and stable.']]);
                }
                $seatIds[$id] = true;
            }
        }

        if ($layout && isset($data['tables'])) {
            // Seat ids are referenced by reservations: a reserved seat cannot disappear.
            $reserved = DB::table('seat_reservations')
                ->whereIn('schedule_id', DB::table('event_schedules')->where('hall_layout_id', $layout->id)->select('id'))
                ->whereIn('status', ['held', 'confirmed'])->pluck('seat_id')->unique();
            $missing = $reserved->reject(fn ($id) => isset($seatIds[$id]));
            if ($missing->isNotEmpty()) {
                throw ApiException::validation('Seats with bookings cannot be removed.', ['tables' => ['Seats '.implode(', ', $missing->take(5)->all()).' have bookings.']]);
            }
        }

        $layout ??= new HallLayout(['user_id' => $user->id]);
        $layout->forceFill([
            'name' => $data['name'] ?? $layout->name ?? 'Untitled layout',
            'width' => (int) ($data['width'] ?? $layout->width ?? 1000),
            'height' => (int) ($data['height'] ?? $layout->height ?? 800),
            'tables' => array_values($tables),
            'fixtures' => array_values($data['fixtures'] ?? ($layout->fixtures ?? [])),
            'total_seats' => count($seatIds),
            'reserved_seats' => (int) ($data['reservedSeats'] ?? collect($tables)->flatMap(fn ($t) => $t['seats'] ?? [])->where('status', 'reserved')->count()),
        ])->save();

        return $layout;
    }

    /** PlannerAssetSummary[] across hall layouts, menus and ready designs. */
    public function assets(User $user, ?string $type, string $sort): array
    {
        $items = collect();
        if (! $type || $type === 'hall_layout') {
            $items = $items->merge(HallLayout::query()->where('user_id', $user->id)->get()->map(fn ($l) => ['id' => $l->id, 'type' => 'hall_layout', 'name' => $l->name, 'thumbnailUrl' => $l->thumbnail_url, 'eventId' => $l->event_id, 'updatedAt' => $l->updated_at]));
        }
        if (! $type || $type === 'menu') {
            $items = $items->merge(PlannerMenu::query()->where('user_id', $user->id)->get()->map(fn ($m) => ['id' => $m->id, 'type' => 'menu', 'name' => $m->name, 'thumbnailUrl' => null, 'eventId' => $m->event_id, 'updatedAt' => $m->updated_at]));
        }
        if (! $type || in_array($type, ['cake_design', 'event_branding'], true)) {
            $items = $items->merge(PlannerGeneration::query()->where('user_id', $user->id)->where('status', 'ready')
                ->when($type, fn ($q) => $q->where('type', $type))->get()
                ->map(fn ($g) => ['id' => $g->id, 'type' => $g->type, 'name' => $g->name ?? 'Design', 'thumbnailUrl' => $g->result_urls[0] ?? null, 'eventId' => $g->event_id, 'updatedAt' => $g->updated_at]));
        }

        $sorted = $sort === 'name' ? $items->sortBy(fn ($i) => mb_strtolower($i['name'])) : $items->sortByDesc('updatedAt');

        return $sorted->values()->map(fn ($i) => array_merge($i, ['updatedAt' => Fmt::date($i['updatedAt'])]))->all();
    }

    public function deleteAsset(User $user, string $id): void
    {
        $model = match (true) {
            str_starts_with($id, 'hall_') => HallLayout::query(),
            str_starts_with($id, 'pmenu_') => PlannerMenu::query(),
            str_starts_with($id, 'pgen_') => PlannerGeneration::query(),
            default => null,
        };
        $asset = $model?->where('user_id', $user->id)->find($id) ?? throw ApiException::notFound('Asset not found.');
        if ($asset instanceof HallLayout && DB::table('event_schedules')->where('hall_layout_id', $id)->exists()) {
            throw ApiException::conflict('This layout is used by an event.');
        }
        $asset->delete();
    }

    public static function message(PlannerChatMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'attachments' => array_values($m->attachments ?? []),
            'generation' => $m->generation ? self::generation($m->generation) : null,
            'createdAt' => Fmt::date($m->created_at),
        ];
    }

    public static function generation(PlannerGeneration $g): array
    {
        return ['id' => $g->id, 'type' => $g->type, 'prompt' => $g->prompt, 'status' => $g->status, 'resultUrls' => array_values($g->result_urls ?? []), 'createdAt' => Fmt::date($g->created_at)];
    }

    public static function menu(PlannerMenu $m): array
    {
        return ['id' => $m->id, 'name' => $m->name, 'sections' => array_values($m->sections ?? []), 'currency' => $m->currency, 'updatedAt' => Fmt::date($m->updated_at)];
    }
}
