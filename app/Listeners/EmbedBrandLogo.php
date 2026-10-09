<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Branded emails (resources/views/components/branded-mail) name their logo by web address. Gmail and others
 * can refuse to load such a picture (blocked remote images, a firewall, an unreachable host), which leaves
 * a broken box. So the logo is fetched here and sent inside the email itself; if that can't be done the
 * web address stays as it was.
 */
class EmbedBrandLogo
{
    private const MAX_BYTES = 400_000;

    public function handle(MessageSending $event): void
    {
        $message = $event->message;
        $html = $message->getHtmlBody();
        if (! is_string($html) || ! preg_match('/<img class="logo" src="(https?:\/\/[^"]+)"/', $html, $m)) {
            return;
        }

        $image = $this->fetch(html_entity_decode($m[1]));
        if ($image === null) {
            return;
        }

        $cid = 'brand-logo-'.substr(sha1($m[1]), 0, 10);
        $message->addPart((new \Symfony\Component\Mime\Part\DataPart($image['body'], $cid, $image['type']))->asInline()->setContentId($cid.'@mail'));
        $message->html(str_replace($m[1], 'cid:'.$cid.'@mail', $html));
    }

    /** @return array{body: string, type: string}|null the picture, kept for a day so every email doesn't fetch it again */
    private function fetch(string $url): ?array
    {
        try {
            $cached = Cache::get('mail-logo:'.sha1($url));
            if (is_array($cached)) {
                return ['body' => base64_decode($cached['body']), 'type' => $cached['type']];
            }

            $response = Http::timeout(5)->get($url);
            $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            $body = $response->body();
            if (! $response->successful() || ! in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) || $body === '' || strlen($body) > self::MAX_BYTES) {
                return null;
            }
            Cache::put('mail-logo:'.sha1($url), ['body' => base64_encode($body), 'type' => $type], now()->addDay());

            return ['body' => $body, 'type' => $type];
        } catch (\Throwable $e) {
            Log::info('Mail logo not embedded', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
