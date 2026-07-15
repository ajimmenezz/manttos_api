<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Long-polling de Telegram para DESARROLLO (sin webhook público / sin ngrok).
 * Recorre las líneas activas de Telegram y procesa sus updates.
 *
 *   php artisan telegram:poll               (bucle continuo)
 *   php artisan telegram:poll --once        (una pasada)
 *   php artisan telegram:poll --channel=3   (una sola línea)
 */
class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll {--once : Una sola pasada} {--channel= : ID de una línea específica}';
    protected $description = 'Sondea Telegram (dev) y procesa mensajes entrantes de captación';

    public function handle(TelegramClient $client, TelegramUpdateProcessor $processor): int
    {
        $this->info('Sondeando Telegram… (Ctrl+C para salir)');

        do {
            $channels = Channel::where('provider', Channel::PROVIDER_TELEGRAM)
                ->where('is_active', true)
                ->when($this->option('channel'), fn ($q) => $q->where('id', $this->option('channel')))
                ->get();

            foreach ($channels as $channel) {
                if (! $channel->token()) {
                    continue;
                }
                $offsetKey = "tg_offset_{$channel->id}";
                $offset = (int) Cache::get($offsetKey, 0);

                try {
                    $updates = $client->getUpdates($channel, $offset, $this->option('once') ? 0 : 25);
                } catch (\Throwable $e) {
                    $this->warn("Línea {$channel->id}: {$e->getMessage()}");
                    continue;
                }

                foreach ($updates as $update) {
                    try {
                        $processor->process($channel, $update);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                    Cache::put($offsetKey, ((int) $update['update_id']) + 1);
                }
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
