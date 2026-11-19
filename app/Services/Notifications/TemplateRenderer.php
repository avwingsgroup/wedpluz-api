<?php

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\Cache;

/**
 * Renders `{{variable}}` templates. Admin-edited templates in
 * `notification_templates` (unique key+channel) override the built-in
 * defaults in DefaultTemplates. Values are HTML-escaped for email bodies.
 */
class TemplateRenderer
{
    /**
     * @param  array<string, scalar|null>  $vars
     * @return array{subject:?string, body:string, whatsappTemplate:?string, variables:list<string>}|null
     */
    public function render(string $key, string $channel, array $vars): ?array
    {
        $template = $this->find($key, $channel);
        if ($template === null || ! $template['isActive']) {
            return null;
        }

        $escape = $channel === 'email';

        return [
            'subject' => $template['subject'] !== null ? $this->fill($template['subject'], $vars, false) : null,
            'body' => $this->fill($template['body'], $vars, $escape),
            'whatsappTemplate' => $template['whatsappTemplate'],
            'variables' => $template['variables'],
        ];
    }

    /** @return array{subject:?string, body:string, isActive:bool, whatsappTemplate:?string, variables:list<string>}|null */
    public function find(string $key, string $channel): ?array
    {
        $stored = Cache::remember('ntpl:'.$key.':'.$channel, 600, function () use ($key, $channel) {
            $t = NotificationTemplate::query()->where('key', $key)->where('channel', $channel)->first();

            return $t ? [
                'subject' => $t->subject,
                'body' => $t->body,
                'isActive' => $t->is_active,
                'whatsappTemplate' => $t->whatsapp_template_name,
                'variables' => $t->variables ?? [],
            ] : false;
        });

        if ($stored !== false) {
            return $stored;
        }

        $default = DefaultTemplates::get($key, $channel);

        return $default ? $default + ['isActive' => true, 'whatsappTemplate' => null] : null;
    }

    public static function forget(string $key, string $channel): void
    {
        Cache::forget('ntpl:'.$key.':'.$channel);
    }

    /** @param array<string, scalar|null> $vars */
    public function fill(string $text, array $vars, bool $escape): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($vars, $escape) {
            $value = (string) ($vars[$m[1]] ?? '');

            return $escape ? e($value) : $value;
        }, $text);
    }
}
