<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Fmt;
use App\Models\HallLayout;
use App\Models\PlannerChat;
use App\Models\PlannerMenu;
use App\Services\Checkout\HallLayoutSeats;
use App\Services\Planner\PlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlannerController extends Controller
{
    public function __construct(private readonly PlannerService $planner) {}

    public function assets(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $type = in_array($type, ['cake_design', 'event_branding', 'menu', 'hall_layout'], true) ? $type : null;

        return $this->ok($this->planner->assets($this->customer(), $type, (string) $request->query('sort', 'recent')));
    }

    public function deleteAsset(string $id): Response
    {
        $this->planner->deleteAsset($this->customer(), $id);

        return $this->noContent();
    }

    public function chats(): JsonResponse
    {
        return $this->ok(PlannerChat::query()->where('user_id', $this->customer()->id)->orderByDesc('updated_at')->limit(100)->get()
            ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'updatedAt' => Fmt::date($c->updated_at)])->all());
    }

    public function createChat(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:200']]);
        $chat = PlannerChat::query()->create(['user_id' => $this->customer()->id, 'title' => $data['title'] ?? 'New chat']);

        return $this->created($this->chat($chat));
    }

    public function showChat(string $id): JsonResponse
    {
        return $this->ok($this->chat($this->ownChat($id)));
    }

    public function deleteChat(string $id): Response
    {
        $this->ownChat($id)->delete();

        return $this->noContent();
    }

    public function sendMessage(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:8000'],
            'attachmentUploadIds' => ['sometimes', 'array', 'max:5'],
            'attachmentUploadIds.*' => ['string', 'max:40'],
            'intent' => ['nullable', 'in:cake_design,event_branding,menu,hall_layout,chat'],
        ]);
        [$user, $assistant] = $this->planner->sendMessage($this->ownChat($id), $this->customer(), $data);

        return $this->ok(['messages' => [PlannerService::message($user), PlannerService::message($assistant)]]);
    }

    public function menus(): JsonResponse
    {
        return $this->ok(PlannerMenu::query()->where('user_id', $this->customer()->id)->latest('updated_at')->get()->map(fn ($m) => PlannerService::menu($m))->all());
    }

    public function showMenu(string $id): JsonResponse
    {
        return $this->ok(PlannerService::menu($this->ownMenu($id)));
    }

    public function createMenu(Request $request): JsonResponse
    {
        return $this->created(PlannerService::menu($this->planner->saveMenu($this->customer(), null, $this->validateMenu($request))));
    }

    public function updateMenu(Request $request, string $id): JsonResponse
    {
        return $this->ok(PlannerService::menu($this->planner->saveMenu($this->customer(), $this->ownMenu($id), $this->validateMenu($request))));
    }

    public function layouts(): JsonResponse
    {
        return $this->ok(HallLayout::query()->where('user_id', $this->customer()->id)->latest('updated_at')->get()->map(fn ($l) => HallLayoutSeats::resource($l))->all());
    }

    public function showLayout(string $id): JsonResponse
    {
        return $this->ok(HallLayoutSeats::resource($this->ownLayout($id)));
    }

    public function createLayout(Request $request): JsonResponse
    {
        return $this->created(HallLayoutSeats::resource($this->planner->saveLayout($this->customer(), null, $this->validateLayout($request))));
    }

    public function updateLayout(Request $request, string $id): JsonResponse
    {
        return $this->ok(HallLayoutSeats::resource($this->planner->saveLayout($this->customer(), $this->ownLayout($id), $this->validateLayout($request))));
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:cake_design,event_branding'], 'prompt' => ['required', 'string', 'min:5', 'max:4000']]);

        return $this->ok(PlannerService::generation($this->planner->generate($this->customer(), $data['type'], $data['prompt'])));
    }

    private function validateMenu(Request $request): array
    {
        return $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'currency' => ['sometimes', 'in:NGN,USD,GBP,EUR'],
            'sections' => ['sometimes', 'array', 'max:30'],
            'sections.*.id' => ['nullable', 'string', 'max:80'],
            'sections.*.title' => ['required', 'string', 'max:120'],
            'sections.*.items' => ['present', 'array', 'max:100'],
            'sections.*.items.*.id' => ['nullable', 'string', 'max:80'],
            'sections.*.items.*.name' => ['required', 'string', 'max:200'],
            'sections.*.items.*.description' => ['nullable', 'string', 'max:1000'],
            'sections.*.items.*.price' => ['required', 'integer', 'min:0'],
            'sections.*.items.*.imageUrl' => ['nullable', 'url', 'max:1024'],
        ]);
    }

    private function validateLayout(Request $request): array
    {
        return $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'width' => ['sometimes', 'integer', 'min:100', 'max:20000'],
            'height' => ['sometimes', 'integer', 'min:100', 'max:20000'],
            'reservedSeats' => ['sometimes', 'integer', 'min:0'],
            'tables' => ['sometimes', 'array', 'max:500'],
            'tables.*.id' => ['required', 'string', 'max:80'],
            'tables.*.label' => ['required', 'string', 'max:60'],
            'tables.*.shape' => ['required', 'in:round,rectangle,row'],
            'tables.*.x' => ['required', 'integer'], 'tables.*.y' => ['required', 'integer'],
            'tables.*.width' => ['required', 'integer', 'min:1'], 'tables.*.height' => ['required', 'integer', 'min:1'],
            'tables.*.rotation' => ['sometimes', 'integer'],
            'tables.*.color' => ['nullable', 'string', 'max:20'],
            'tables.*.seats' => ['present', 'array', 'max:200'],
            'tables.*.seats.*.id' => ['required', 'string', 'max:80'],
            'tables.*.seats.*.label' => ['required', 'string', 'max:60'],
            'tables.*.seats.*.status' => ['sometimes', 'in:available,taken,reserved,selected'],
            'tables.*.seats.*.ticketTypeId' => ['nullable', 'string', 'max:40'],
            'fixtures' => ['sometimes', 'array', 'max:200'],
            'fixtures.*.id' => ['required', 'string', 'max:80'],
            'fixtures.*.label' => ['required', 'string', 'max:120'],
            'fixtures.*.type' => ['required', 'in:stage,dance_floor,dj,cake,entrance,note,special'],
            'fixtures.*.x' => ['required', 'integer'], 'fixtures.*.y' => ['required', 'integer'],
            'fixtures.*.width' => ['required', 'integer', 'min:1'], 'fixtures.*.height' => ['required', 'integer', 'min:1'],
        ]);
    }

    private function chat(PlannerChat $chat): array
    {
        $messages = $chat->messages()->with('generation')->orderBy('created_at')->orderBy('id')->get();

        return [
            'id' => $chat->id,
            'title' => $chat->title,
            'messages' => $messages->map(fn ($m) => PlannerService::message($m))->all(),
            'createdAt' => Fmt::date($chat->created_at),
            'updatedAt' => Fmt::date($chat->updated_at),
        ];
    }

    private function ownChat(string $id): PlannerChat
    {
        return PlannerChat::query()->where('user_id', $this->customer()->id)->find($id) ?? throw ApiException::notFound('Chat not found.');
    }

    private function ownMenu(string $id): PlannerMenu
    {
        return PlannerMenu::query()->where('user_id', $this->customer()->id)->find($id) ?? throw ApiException::notFound('Menu not found.');
    }

    private function ownLayout(string $id): HallLayout
    {
        return HallLayout::query()->where('user_id', $this->customer()->id)->find($id) ?? throw ApiException::notFound('Hall layout not found.');
    }
}
