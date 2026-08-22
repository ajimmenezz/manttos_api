<?php
/**
 * snapshot-export-standalone.php — respaldo portable SIN desplegar código nuevo.
 *
 * Produce exactamente el mismo ZIP que `php artisan snapshot:export` (database.sql +
 * media/ + manifest.json), pero sin depender de que el servidor tenga los commits
 * nuevos: no arranca Laravel, sólo lee el .env y llama a pg_dump.
 *
 * Uso (desde la raíz del API de producción, la carpeta que contiene .env y artisan):
 *   php tools/snapshot-export-standalone.php             # base + archivos
 *   php tools/snapshot-export-standalone.php --no-media  # sólo la base (rápido)
 *   php tools/snapshot-export-standalone.php --size      # sólo medir, no genera nada
 *
 * El ZIP queda en storage/app/snapshots/. Bórralo del servidor cuando lo hayas bajado
 * (lleva datos reales), y borra también este archivo.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Sólo por consola.\n");
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

// Funciona en la raíz del API o dentro de tools/ (o desde donde se invoque).
$root = null;
foreach ([__DIR__, dirname(__DIR__), getcwd()] as $candidate) {
    if (is_file($candidate . '/.env') && is_file($candidate . '/artisan')) { $root = $candidate; break; }
}

if ($root === null) {
    fail("No encontré la instalación: necesito una carpeta con .env y artisan. Copia este archivo dentro del API y vuelve a correrlo.");
}

$opts     = $argv;
$noMedia  = in_array('--no-media', $opts, true);
$sizeOnly = in_array('--size', $opts, true);

/* ── .env ──────────────────────────────────────────────────────────────────── */

$env = parseEnv($root . '/.env');

$conn = [
    'host'     => $env['DB_HOST']     ?? '127.0.0.1',
    'port'     => $env['DB_PORT']     ?? '5432',
    'database' => $env['DB_DATABASE'] ?? '',
    'user'     => $env['DB_USERNAME'] ?? '',
    'password' => $env['DB_PASSWORD'] ?? '',
];

if ($conn['database'] === '') fail('No pude leer DB_DATABASE del .env.');

/* ── pg_dump ───────────────────────────────────────────────────────────────── */

$pgDump = findBinary('pg_dump', $env['PG_BIN'] ?? '');
if (! $pgDump) {
    fail("No encontré pg_dump en este servidor.\n" .
         "Busca dónde está (`which pg_dump`, `ls /usr/lib/postgresql/*/bin`) y define\n" .
         "PG_BIN=/ruta/a/la/carpeta en el .env, o pásalo así:\n" .
         "  PG_BIN=/usr/lib/postgresql/16/bin php snapshot-export-standalone.php");
}

/* ── archivos (dos discos: público y privado) ──────────────────────────────── */

$mediaDirs = ['app/public', 'app/private'];
$media     = [];

if (! $noMedia) {
    foreach ($mediaDirs as $relative) {
        $base = $root . '/storage/' . $relative;
        if (! is_dir($base)) continue;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile() || is_link($file->getPathname())) continue;   // storage/app/public/storage se recrea con storage:link

            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));

            // `snapshot:import` restaura con File::allFiles(), que ignora los ocultos
            // (Symfony Finder → ignoreDotFiles). Si los metiéramos al ZIP, el destino
            // no los copiaría y la verificación reportaría un faltante que no lo es.
            if (str_starts_with(basename($rel), '.') || str_contains($rel, '/.')) continue;

            $media[$file->getPathname()] = 'media/' . $relative . '/' . $rel;
        }
    }
}

$mediaBytes = array_sum(array_map('filesize', array_keys($media)));

if ($sizeOnly) {
    line('Archivos subidos: ' . count($media) . ' (' . human($mediaBytes) . ')');
    foreach ($mediaDirs as $relative) {
        $base = $root . '/storage/' . $relative;
        line('  ' . $relative . ': ' . (is_dir($base) ? human(dirSize($base)) : 'no existe'));
    }
    exit(0);
}

/* ── volcado ───────────────────────────────────────────────────────────────── */

$temp = $root . '/storage/app/snapshot-tmp-' . uniqid();
@mkdir($temp, 0775, true);
$sql = $temp . '/database.sql';

line("Exportando la base «{$conn['database']}»…");

putenv('PGPASSWORD=' . $conn['password']);

$cmd = escapeshellarg($pgDump)
    . ' --host=' . escapeshellarg($conn['host'])
    . ' --port=' . escapeshellarg($conn['port'])
    . ' --username=' . escapeshellarg($conn['user'])
    . ' --dbname=' . escapeshellarg($conn['database'])
    . ' --format=plain --clean --if-exists --no-owner --no-privileges --encoding=UTF8'
    . ' --file=' . escapeshellarg($sql)
    . ' 2>&1';

exec($cmd, $out, $status);
putenv('PGPASSWORD');

if ($status !== 0 || ! is_file($sql) || filesize($sql) === 0) {
    cleanup($temp);
    fail("pg_dump falló:\n" . implode("\n", $out));
}

line('  base de datos: ' . human(filesize($sql)));
if ($media) line('  archivos: ' . count($media) . ' (' . human($mediaBytes) . ')');

/* ── manifiesto (lo que el import usa para verificar y reapuntar URLs) ─────── */

$counts        = [];
$pgVersion     = null;
$lastMigration = null;

try {
    $pdo = new PDO(
        "pgsql:host={$conn['host']};port={$conn['port']};dbname={$conn['database']}",
        $conn['user'],
        $conn['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $pgVersion = $pdo->query('select version()')->fetchColumn() ?: null;

    $tables = $pdo->query("select tablename from pg_tables where schemaname = 'public' order by tablename")
        ->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        try {
            $counts[$table] = (int) $pdo->query('select count(*) from "' . $table . '"')->fetchColumn();
        } catch (Throwable) {
            // una vista o una tabla sin permiso no debe tumbar el respaldo
        }
    }

    $lastMigration = $pdo->query('select migration from migrations order by id desc limit 1')->fetchColumn() ?: null;
} catch (Throwable $e) {
    line('  aviso: no pude contar las tablas (' . $e->getMessage() . '). El import no podrá autoverificarse.');
}

$manifest = [
    'generated_at'   => date('c'),
    'app_name'       => $env['APP_NAME'] ?? 'Mantenimientos',
    'app_url'        => rtrim($env['APP_URL'] ?? '', '/'),   // ← con esto el import reapunta las fotos al dominio local
    'environment'    => $env['APP_ENV'] ?? 'production',
    'database'       => $conn['database'],
    'pg_version'     => $pgVersion,
    'last_migration' => $lastMigration,
    'media_files'    => count($media),
    'media_bytes'    => $mediaBytes,
    'counts'         => $counts,
];

/* ── ZIP ───────────────────────────────────────────────────────────────────── */

$dir = $root . '/storage/app/snapshots';
@mkdir($dir, 0775, true);
$zipPath = $dir . '/snapshot-' . $conn['database'] . '-' . date('Ymd-His') . ($noMedia ? '-sindatos' : '') . '.zip';

line('Empaquetando…');

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    cleanup($temp);
    fail("No pude crear el ZIP en {$zipPath}.");
}

$zip->addFile($sql, 'database.sql');
foreach ($media as $absolute => $inside) $zip->addFile($absolute, $inside);
$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

if (! $zip->close()) {
    cleanup($temp);
    fail('Falló el cierre del ZIP (¿espacio en disco?).');
}

cleanup($temp);

line('');
line('Respaldo listo:');
line('  ' . number_format(array_sum($counts)) . ' registros en ' . count($counts) . ' tablas');
line('  ' . $zipPath);
line('  ' . human(filesize($zipPath)));
line('');
line('Bájalo por SFTP o por el administrador de archivos de Plesk y BÓRRALO del servidor.');
line('En local: php artisan snapshot:import ' . basename($zipPath));

exit(0);

/* ── helpers ───────────────────────────────────────────────────────────────── */

function parseEnv(string $path): array
{
    $env = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $raw) {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, '#') || ! str_contains($raw, '=')) continue;

        [$key, $value] = explode('=', $raw, 2);
        $key   = trim($key);
        $value = trim($value);

        // valores entrecomillados: "pa ss@word" / 'x'
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

/** Busca el binario en PG_BIN, en el PATH y en las rutas típicas (la versión más nueva primero). */
function findBinary(string $name, string $hint): ?string
{
    $windows = DIRECTORY_SEPARATOR === '\\';
    $exe     = $windows ? $name . '.exe' : $name;

    if ($hint !== '') {
        $candidate = rtrim($hint, '/\\') . DIRECTORY_SEPARATOR . $exe;
        if (is_executable($candidate)) return $candidate;
    }

    $which = @shell_exec(($windows ? 'where ' : 'command -v ') . escapeshellarg($name) . ' 2>' . ($windows ? 'NUL' : '/dev/null'));
    $which = trim((string) strtok((string) $which, "\r\n"));
    if ($which !== '' && is_executable($which)) return $which;

    $globs = glob('/usr/lib/postgresql/*/bin/' . $exe) ?: [];
    $globs = array_merge($globs, glob('/usr/pgsql-*/bin/' . $exe) ?: []);
    $globs = array_merge($globs, glob('C:/Program Files/PostgreSQL/*/bin/' . $exe) ?: []);
    usort($globs, 'strnatcmp');

    foreach (array_reverse($globs) as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }

    return null;
}

function dirSize(string $dir): int
{
    $bytes = 0;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($it as $file) {
        if ($file->isFile() && ! is_link($file->getPathname())) $bytes += $file->getSize();
    }

    return $bytes;
}

function human(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i     = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }

    return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
}

function cleanup(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}

function line(string $message): void { fwrite(STDOUT, $message . PHP_EOL); }

function fail(string $message): void { fwrite(STDERR, $message . PHP_EOL); exit(1); }
