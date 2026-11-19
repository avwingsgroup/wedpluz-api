<?php

namespace App\Integrations\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Integrations\Support\IntegrationException;
use App\Models\IntegrationLog;

/**
 * Pluz Planner assistant on the Claude Messages API (official Anthropic PHP SDK).
 *
 * - Model `claude-opus-5-5` by default (config services.anthropic.model), with an
 *   explicit effort level (Opus 5.5 defaults to `medium`).
 * - Server-side refusal fallbacks are enabled (`fallbacks: "default"`), so a
 *   safety-classifier refusal is retried on a fallback model chosen by the API.
 *   A refusal that still stands is returned as a polite message, never an error.
 * - The SDK retries 408/409/429/5xx and connection errors itself (maxRetries);
 *   remaining failures map to IntegrationException (transient vs permanent).
 */
class PlannerAssistant
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are Pluz Planner, the event-planning assistant inside Wedpluz, a Nigerian platform for weddings and celebrations where people create events, sell tickets and hire vendors.

Help the user plan their event: budgets (in Naira unless they say otherwise), timelines and checklists, guest management, menus, hall layouts and seating, decor and themes, vendor categories to hire and questions to ask them, and etiquette for Nigerian and international celebrations.

Be practical and specific. Use short paragraphs and lists where they help. When you are unsure about local prices or availability, give a sensible range and say it is an estimate. You cannot book vendors, sell tickets or change anything in the user's account; when that is what they need, tell them which part of Wedpluz to use (Create Event, Hire Vendors, Pluz Planner menus or hall layouts).
PROMPT;

    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']);
    }

    /**
     * @param  list<array{role:'user'|'assistant', content:string}>  $history  oldest first, ending with the new user message
     * @return array{content:string, inputTokens:int, outputTokens:int, refused:bool}
     */
    public function reply(array $history, ?string $intent = null): array
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('anthropic');
        }

        $client = new Client(
            apiKey: (string) $this->config['api_key'],
            requestOptions: ['timeout' => (float) ($this->config['timeout'] ?? 60), 'maxRetries' => 2],
        );

        $system = self::SYSTEM_PROMPT;
        if ($intent !== null && $intent !== 'chat') {
            $system .= "\n\nThe user is currently working on: ".str_replace('_', ' ', $intent).'.';
        }

        $started = microtime(true);
        try {
            $message = $client->beta->messages->create(
                model: (string) ($this->config['model'] ?? 'claude-opus-5-5'),
                maxTokens: (int) ($this->config['max_tokens'] ?? 16000),
                system: $system,
                messages: $history,
                outputConfig: ['effort' => (string) ($this->config['effort'] ?? 'medium')],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (RateLimitException $e) {
            $this->log(false, $started, $e->status);
            throw IntegrationException::transient('anthropic', 'messages', 'The assistant is busy. Please try again shortly.', '429', $e);
        } catch (AuthenticationException|PermissionDeniedException $e) {
            $this->log(false, $started, $e->status);
            throw IntegrationException::permanent('anthropic', 'messages', 'The assistant is not configured correctly.', (string) $e->status);
        } catch (BadRequestException $e) {
            $this->log(false, $started, $e->status);
            throw IntegrationException::permanent('anthropic', 'messages', 'The assistant could not process this conversation.', '400');
        } catch (APIStatusException $e) {
            $this->log(false, $started, $e->status);
            throw IntegrationException::fromStatus('anthropic', 'messages', (int) $e->status, 'The assistant is temporarily unavailable.');
        } catch (APIConnectionException $e) {
            $this->log(false, $started, null);
            throw IntegrationException::transient('anthropic', 'messages', 'Could not reach the assistant.', null, $e);
        }

        $this->log(true, $started, 200);

        if ($message->stopReason === 'refusal') {
            return [
                'content' => 'I can\'t help with that request. I\'m happy to help with any other part of planning your event.',
                'inputTokens' => $message->usage->inputTokens,
                'outputTokens' => $message->usage->outputTokens,
                'refused' => true,
            ];
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return [
            'content' => trim($text) !== '' ? trim($text) : 'Sorry, I couldn\'t come up with an answer. Could you rephrase that?',
            'inputTokens' => $message->usage->inputTokens,
            'outputTokens' => $message->usage->outputTokens,
            'refused' => false,
        ];
    }

    private function log(bool $ok, float $started, ?int $status): void
    {
        try {
            IntegrationLog::query()->create([
                'provider' => 'anthropic',
                'operation' => 'messages.create',
                'status_code' => $status,
                'success' => $ok,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'correlation_id' => request()?->attributes->get('correlation_id'),
            ]);
        } catch (\Throwable) {
            // logging must never break the request
        }
    }
}
