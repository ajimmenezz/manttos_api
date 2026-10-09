<?php

namespace App\Services\Storage;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente mínimo de la API de Google Drive v3, hablando como la cuenta de servicio.
 *
 * No se usa `google/apiclient`: trae miles de clases para una docena de llamadas.
 * `google/auth` (ya instalado por el push) firma el JWT y da el token; lo demás es
 * HTTP. Toda llamada lleva `supportsAllDrives`: sin él, Drive responde 404 sobre
 * cualquier cosa que viva en una unidad compartida.
 *
 * La cuenta de servicio es «Gestor de contenido» de UNA unidad: puede crear, mover y
 * renombrar ahí, pero no tocar miembros ni configuración, y no ve nada fuera de ella.
 */
class GoogleDrive
{
    private const API = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3';
    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    public function driveId(): string
    {
        $id = config('services.google_drive.drive_id');
        if (!$id) throw new RuntimeException('Falta GOOGLE_DRIVE_ID en el .env.');
        return $id;
    }

    /**
     * Dónde cuelgan Clientes/Libres/_papelera: la raíz de la unidad o, si se configura,
     * una carpeta dentro de ella. Desarrollo usa su propia carpeta para no sembrar
     * carpetas junto a las de producción en la MISMA unidad.
     */
    public function rootId(): string
    {
        return config('services.google_drive.root_id') ?: $this->driveId();
    }

    /**
     * Marca de esta instalación en cada archivo. `mtto_file_id` es el id de NUESTRO renglón,
     * y desarrollo y producción tienen ids que se repiten: sin la marca, el rescate de
     * subidas de una podría dar por buena la de la otra.
     */
    public function installTag(): string
    {
        return substr(md5((string) config('app.url')), 0, 12);
    }

    public function configured(): bool
    {
        return (bool) config('services.google_drive.drive_id')
            && is_file((string) config('services.google_drive.credentials'));
    }

    private function token(): string
    {
        // El token dura una hora; se guarda 50 minutos.
        return Cache::remember('storage.drive.token', 3000, function () {
            $path = (string) config('services.google_drive.credentials');
            if (!is_file($path)) throw new RuntimeException("No se encontró la llave de Google Drive en {$path}.");

            $creds = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/drive',
                json_decode(file_get_contents($path), true),
            );
            $token = $creds->fetchAuthToken()['access_token'] ?? null;
            if (!$token) throw new RuntimeException('Google no entregó token para la cuenta de servicio.');
            return $token;
        });
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token())->timeout(30)->retry(2, 500, throw: false);
    }

    private function ok(Response $r, string $what): array
    {
        if ($r->failed()) {
            if ($r->status() === 401) Cache::forget('storage.drive.token');
            throw new RuntimeException("Google Drive ({$what}): HTTP {$r->status()} " . mb_substr($r->body(), 0, 300));
        }
        return $r->json() ?? [];
    }

    public function createFolder(string $name, string $parentDriveId, array $appProperties = []): string
    {
        $r = $this->http()->post(self::API . '/files?supportsAllDrives=true&fields=id', [
            'name' => $name,
            'mimeType' => self::FOLDER_MIME,
            'parents' => [$parentDriveId],
            'appProperties' => (object) $appProperties,
        ]);
        return $this->ok($r, 'crear carpeta')['id'];
    }

    public function rename(string $driveId, string $name): void
    {
        $this->ok($this->http()->patch(self::API . "/files/{$driveId}?supportsAllDrives=true&fields=id", ['name' => $name]), 'renombrar');
    }

    public function updateDescription(string $driveId, string $description): void
    {
        $this->ok($this->http()->patch(self::API . "/files/{$driveId}?supportsAllDrives=true&fields=id", ['description' => $description]), 'descripción');
    }

    /** Mueve a otra carpeta. Drive necesita saber de dónde sale (`removeParents`). */
    public function move(string $driveId, string $newParentDriveId): void
    {
        $current = $this->get($driveId, 'parents')['parents'] ?? [];
        $query = http_build_query([
            'supportsAllDrives' => 'true',
            'addParents' => $newParentDriveId,
            'removeParents' => implode(',', $current),
            'fields' => 'id,parents',
        ]);
        $this->ok($this->http()->patch(self::API . "/files/{$driveId}?{$query}", (object) []), 'mover');
    }

    public function get(string $driveId, string $fields = 'id,name,mimeType,size,md5Checksum,sha256Checksum,parents,appProperties,trashed'): array
    {
        return $this->ok($this->http()->get(self::API . "/files/{$driveId}", [
            'supportsAllDrives' => 'true',
            'fields' => $fields,
        ]), 'leer');
    }

    /** Busca en la unidad por una propiedad de la app (p. ej. el id de nuestro renglón). */
    public function findByAppProperty(string $key, string $value): ?array
    {
        $r = $this->ok($this->http()->get(self::API . '/files', [
            'corpora' => 'drive',
            'driveId' => $this->driveId(),
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives' => 'true',
            'q' => sprintf(
                "appProperties has { key='%s' and value='%s' } and appProperties has { key='mtto_install' and value='%s' } and trashed = false",
                addslashes($key), addslashes($value), $this->installTag(),
            ),
            'fields' => 'files(id,name,mimeType,size,md5Checksum,sha256Checksum,parents,appProperties)',
            'pageSize' => 5,
        ]), 'buscar');
        return $r['files'][0] ?? null;
    }

    /**
     * Abre una sesión de subida reanudable y devuelve su URL. El cliente (navegador o
     * teléfono) hace el PUT con los bytes directo a esa URL: el archivo NO pasa por
     * nuestro servidor. Con `$origin` Google responde con CORS para ese origen, que es
     * lo que deja al navegador subir; el teléfono no lo necesita.
     *
     * La URL basta para subir a ESE archivo durante una semana: se entrega solo a
     * quien abrió la sesión y se borra del renglón al confirmar.
     */
    public function startResumableUpload(array $metadata, string $mime, int $size, ?string $origin = null): string
    {
        $headers = [
            'X-Upload-Content-Type' => $mime ?: 'application/octet-stream',
            'X-Upload-Content-Length' => (string) $size,
        ];
        if ($origin) $headers['Origin'] = $origin;

        $r = Http::withToken($this->token())->timeout(30)->withHeaders($headers)
            ->post(self::UPLOAD . '/files?uploadType=resumable&supportsAllDrives=true&fields=id,name,size,mimeType', $metadata);
        $this->ok($r, 'abrir subida');

        $location = $r->header('Location');
        if (!$location) throw new RuntimeException('Google Drive no devolvió la URL de subida.');
        return $location;
    }

    /**
     * Descarga como stream (para pasarlo al navegador sin cargarlo en memoria).
     * Reenvía `Range` para que el video y los PDF grandes se puedan adelantar.
     */
    public function download(string $driveId, ?string $range = null): \Psr\Http\Message\ResponseInterface
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token()];
        if ($range) $headers['Range'] = $range;

        return (new \GuzzleHttp\Client(['http_errors' => false]))->get(
            self::API . "/files/{$driveId}?alt=media&supportsAllDrives=true",
            ['headers' => $headers, 'stream' => true, 'timeout' => 0, 'connect_timeout' => 15],
        );
    }

    /** Miniatura que Drive genera para imágenes, PDF y video. Requiere el token: se pasa por nosotros. */
    public function thumbnail(string $driveId, int $size = 400): ?array
    {
        $link = $this->get($driveId, 'thumbnailLink')['thumbnailLink'] ?? null;
        if (!$link) return null;
        $link = preg_replace('/=s\d+$/', "=s{$size}", $link);

        $r = Http::withToken($this->token())->timeout(20)->get($link);
        if ($r->failed()) return null;
        return ['body' => $r->body(), 'type' => $r->header('Content-Type') ?: 'image/jpeg'];
    }
}
