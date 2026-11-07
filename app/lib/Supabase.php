<?php
declare(strict_types=1);

/**
 * Supabase - optionale Cloud-Ablage fuer die Medienbibliothek.
 *
 * Es werden zwei Dinge gespeichert:
 *  - die Bilddateien selbst   -> Storage-Bucket
 *  - wo sie eingesetzt werden -> Tabelle (Spalte "usages", JSON)
 *
 * Der Service-Key liegt ausschliesslich in app/config.php (0600) und wird
 * niemals an den Browser ausgeliefert. Ist nichts konfiguriert, arbeitet der
 * Editor rein lokal weiter; alle Cloud-Aufrufe sind dann "skipped".
 *
 * Erwartetes Tabellenschema (SQL steht im Handbuch):
 *   create table public.media (
 *     path text primary key,
 *     bucket text not null,
 *     storage_path text not null,
 *     size bigint not null,
 *     mime text,
 *     usages jsonb not null default '[]'::jsonb,
 *     updated_at timestamptz not null default now()
 *   );
 */
final class Supabase
{
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'url' => '',
            'key' => '',
            'bucket' => 'media',
            'table' => 'media',
        ];
    }

    public static function config(): array
    {
        $cfg = Config::all()['supabase'] ?? [];
        return array_replace(self::defaults(), is_array($cfg) ? $cfg : []);
    }

    public static function configured(): bool
    {
        $c = self::config();
        return trim((string) $c['url']) !== '' && trim((string) $c['key']) !== '';
    }

    public static function active(): bool
    {
        return self::configured() && !empty(self::config()['enabled']);
    }

    /** Status fuer die Oberflaeche - ohne Schluessel. */
    public static function status(): array
    {
        $c = self::config();
        return [
            'configured' => self::configured(),
            'active' => self::active(),
            'url' => rtrim((string) $c['url'], '/'),
            'bucket' => (string) $c['bucket'],
            'table' => (string) $c['table'],
        ];
    }

    private static function base(): string
    {
        return rtrim((string) self::config()['url'], '/');
    }

    private static function table(): string
    {
        return (string) self::config()['table'];
    }

    private static function bucket(): string
    {
        return (string) self::config()['bucket'];
    }

    /** @param array<int,string> $headers */
    private static function request(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 20): array
    {
        $key = (string) self::config()['key'];
        $head = array_merge([
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
        ], $headers);
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl ist nicht verfuegbar.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $head,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [
            'ok' => $err === '' && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => is_string($out) ? $out : '',
            'error' => $err,
        ];
    }

    /** Prueft Erreichbarkeit von Tabelle und Bucket. */
    public static function test(): array
    {
        if (!self::configured()) {
            throw new AppError('Supabase ist nicht konfiguriert (URL und Service-Key fehlen).', 422);
        }
        $table = rawurlencode(self::table());
        $bucket = rawurlencode(self::bucket());
        $t = self::request('GET', self::base() . '/rest/v1/' . $table . '?select=path&limit=1', null, ['Accept: application/json']);
        $b = self::request('GET', self::base() . '/storage/v1/bucket/' . $bucket, null, ['Accept: application/json']);
        $problem = '';
        if (!$t['ok']) {
            $problem = self::explain($t, 'Tabelle "' . self::table() . '"');
        } elseif (!$b['ok']) {
            $problem = self::explain($b, 'Bucket "' . self::bucket() . '"');
        }
        return [
            'ok' => $t['ok'] && $b['ok'],
            'table' => ['ok' => $t['ok'], 'status' => $t['status']],
            'bucket' => ['ok' => $b['ok'], 'status' => $b['status']],
            'problem' => $problem,
        ];
    }

    private static function explain(array $res, string $what): string
    {
        $hint = match ($res['status']) {
            401, 403 => 'Schluessel abgelehnt (Service-Role-Key verwenden).',
            404 => $what . ' existiert nicht.',
            400 => 'Anfrage abgelehnt (Schema pruefen).',
            default => $res['error'] !== '' ? $res['error'] : 'Antwort ' . $res['status'],
        };
        return $what . ': ' . $hint;
    }

    /** Laedt eine Datei in den Bucket (ueberschreibt vorhandene Objekte). */
    public static function upload(string $objectPath, string $bytes, string $mime): array
    {
        if (!self::active()) {
            return ['ok' => false, 'skipped' => true, 'error' => 'Supabase ist nicht aktiv.'];
        }
        $object = ltrim($objectPath, '/');
        $segments = array_map('rawurlencode', explode('/', $object));
        $url = self::base() . '/storage/v1/object/' . rawurlencode(self::bucket()) . '/' . implode('/', $segments);
        $res = self::request('POST', $url, $bytes, [
            'Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'),
            'x-upsert: true',
        ], 45);
        return ['ok' => $res['ok'], 'status' => $res['status'], 'error' => $res['ok'] ? '' : self::explain($res, 'Upload')];
    }

    /**
     * Schreibt/aktualisiert Zeilen. on_conflict=path sorgt fuer Upsert.
     * @param array<int,array> $rows
     */
    public static function upsertRows(array $rows): array
    {
        if (!self::active()) {
            return ['ok' => false, 'skipped' => true, 'error' => 'Supabase ist nicht aktiv.'];
        }
        if (!$rows) {
            return ['ok' => true, 'status' => 200, 'error' => ''];
        }
        $json = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'Daten konnten nicht serialisiert werden.'];
        }
        $url = self::base() . '/rest/v1/' . rawurlencode(self::table()) . '?on_conflict=path';
        $res = self::request('POST', $url, $json, [
            'Content-Type: application/json',
            'Prefer: resolution=merge-duplicates,return=minimal',
        ]);
        return ['ok' => $res['ok'], 'status' => $res['status'], 'error' => $res['ok'] ? '' : self::explain($res, 'Tabelle')];
    }

    /** Oeffentliche Objekt-URL (nur sinnvoll bei public Bucket). */
    public static function publicUrl(string $objectPath): string
    {
        $object = ltrim($objectPath, '/');
        $segments = array_map('rawurlencode', explode('/', $object));
        return self::base() . '/storage/v1/object/public/' . rawurlencode(self::bucket()) . '/' . implode('/', $segments);
    }
}
