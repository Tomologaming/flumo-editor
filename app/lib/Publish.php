<?php
declare(strict_types=1);

/**
 * Publish - Entwuerfe veroeffentlichen, sichern, zuruecksetzen.
 *
 * working/ = Entwurf (Quelle fuer Vorschau und Bearbeitung)
 * live/    = zuletzt veroeffentlichter Stand (Vergleichsbasis)
 * FTP-Server = oeffentliche Website
 */
final class Publish
{
    public static function changes(): array
    {
        $diff = Store::diff(Store::working(), Store::live());
        return $diff;
    }

    public static function preview(): array
    {
        $changes = self::changes();
        $affected = Validator::affected($changes);
        $validation = $changes ? Validator::validate(array_keys($changes), false) : ['issues' => [], 'counts' => ['error' => 0, 'warning' => 0, 'info' => 0]];
        $pages = Sync::pages();
        return [
            'changes' => $changes,
            'affected' => $affected,
            'validation' => $validation,
            'pages' => $pages,
            'sourceType' => Config::source()['type'] ?? 'ftp',
            'pending' => Languages::pendingSummary(),
        ];
    }

    public static function publish(string $message, array $onlyFiles = []): array
    {
        Auth::requirePerm('publish');
        $changes = self::changes();
        if (!$changes) {
            throw new AppError('Es gibt keine Aenderungen zum Veroeffentlichen.');
        }
        if ($onlyFiles) {
            $filtered = [];
            foreach ($changes as $rel => $info) {
                if (in_array($rel, $onlyFiles, true)) {
                    $filtered[$rel] = $info;
                }
            }
            if (!$filtered) {
                throw new AppError('Die ausgewaehlten Dateien haben keine Aenderungen.');
            }
            $changes = $filtered;
        }

        $validation = Validator::validate(array_keys($changes), false);
        if ($validation['counts']['error'] > 0) {
            throw new AppError('Veroeffentlichung blockiert: ' . $validation['counts']['error'] . ' Fehler in der Pruefung. Bitte zuerst beheben.', 422);
        }

        $uploads = [];
        $deletes = [];
        foreach ($changes as $rel => $info) {
            if ($info['status'] === 'deleted') {
                $deletes[] = $rel;
            } else {
                $uploads[] = $rel;
            }
        }

        $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        $backupDir = Store::backups() . '/' . $id;
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            throw new AppError('Backup-Verzeichnis konnte nicht erstellt werden.', 500);
        }

        // 1. Lokalen Zustand vor der Veroeffentlichung sichern
        $manifest = [
            'id' => $id,
            'createdAt' => date('c'),
            'user' => Auth::user()['name'] ?? null,
            'message' => $message,
            'type' => 'publish',
            'files' => array_keys($changes),
            'remoteBackup' => [],
            'verify' => [],
        ];
        foreach ($uploads as $rel) {
            Store::copyFile($rel, Store::working(), $backupDir . '/working');
            $manifest['verify'][$rel] = Store::hashOf($rel, $backupDir . '/working');
        }

        // 2. Remote-Originale sichern (Rueckfallebene vor dem Ueberschreiben)
        $sourceType = Config::source()['type'] ?? 'ftp';
        $remoteBackupOk = false;
        if ($sourceType === 'ftp' && !empty(Config::source()['host'])) {
            try {
                $ftp = Sync::ftp();
                $ftp->connect();
                foreach (array_merge($uploads, $deletes) as $rel) {
                    $ok = Sync::fetchRemoteToBackup($ftp, $rel, $backupDir . '/remote');
                    $manifest['remoteBackup'][$rel] = $ok;
                }
                $ftp->close();
                $remoteBackupOk = true;
            } catch (Throwable $e) {
                $manifest['remoteBackupError'] = $e->getMessage();
            }
        }

        // 3. Lokale Backups pruefen (ein Backup gilt erst als gueltig, wenn es stimmt)
        foreach ($manifest['verify'] as $rel => $hash) {
            $check = Store::hashOf($rel, $backupDir . '/working');
            if ($hash === null || $check !== $hash) {
                throw new AppError('Backup-Pruefung fehlgeschlagen fuer ' . $rel . '. Veroeffentlichung abgebrochen.', 500);
            }
        }
        Store::writeJson($backupDir . '/meta.json', $manifest);

        // 4. Uebertragen
        $push = ['uploaded' => [], 'deleted' => [], 'failed' => [], 'warnings' => []];
        if ($sourceType === 'ftp' && !empty(Config::source()['host'])) {
            $push = Sync::pushFiles($uploads, $deletes);
        } else {
            $push['warnings'][] = 'Kein FTP konfiguriert: Aenderungen gelten nur lokal als veroeffentlicht.';
            $push['uploaded'] = $uploads;
            $push['deleted'] = $deletes;
        }

        // 5. Live-Spiegel nur fuer tatsaechlich uebertragene Dateien aktualisieren
        $failedFiles = array_column($push['failed'], 'file');
        $applied = [];
        foreach ($push['uploaded'] as $rel) {
            if (in_array($rel, $failedFiles, true)) {
                continue;
            }
            Store::copyFile($rel, Store::working(), Store::live());
            $applied[] = $rel;
        }
        foreach ($push['deleted'] as $rel) {
            if (in_array($rel, $failedFiles, true)) {
                continue;
            }
            Store::unlinkRel($rel, Store::live());
            $applied[] = $rel;
        }

        $manifest['applied'] = $applied;
        $manifest['failed'] = $push['failed'];
        $manifest['remoteBackupOk'] = $remoteBackupOk;
        $manifest['liveCheck'] = self::liveCheck(array_values(array_filter($applied, static function ($rel) {
            return in_array(Store::extOf($rel), ['html', 'htm', 'php'], true);
        })));
        Store::writeJson($backupDir . '/meta.json', $manifest);

        Config::save(array_merge(Config::all(), ['lastPublish' => date('c')]));
        Journal::write('publish', null, [
            'id' => $id,
            'files' => count($applied),
            'failed' => count($push['failed']),
            'message' => $message,
        ]);

        return [
            'id' => $id,
            'applied' => $applied,
            'failed' => $push['failed'],
            'warnings' => $push['warnings'],
            'liveCheck' => $manifest['liveCheck'],
            'remoteBackupOk' => $remoteBackupOk,
            'changesLeft' => count(self::changes()),
            'validation' => $validation,
        ];
    }

    /** Prueft nach der Veroeffentlichung die oeffentlich erreichbaren Seiten. */
    public static function liveCheck(array $files): array
    {
        $base = rtrim((string) (Config::all()['baseUrl'] ?? ''), '/');
        $paths = [];
        foreach ($files as $rel) {
            $path = $rel;
            $path = preg_replace('/\.php$/i', '', $path) ?? $path;
            $path = preg_replace('/\/index\.html?$/i', '/', $path) ?? $path;
            $paths[$rel] = $path;
        }
        if ($base === '' || !function_exists('curl_init')) {
            return ['available' => false, 'reason' => $base === '' ? 'Keine oeffentliche Basis-URL konfiguriert.' : 'curl nicht verfuegbar.', 'results' => []];
        }
        $results = [];
        foreach ($paths as $rel => $path) {
            $url = $base . $path;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT => 'WebsiteEditor-LiveCheck/1.0',
                CURLOPT_NOBODY => true,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($code === 0 || $code === 405) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_TIMEOUT => 12,
                    CURLOPT_USERAGENT => 'WebsiteEditor-LiveCheck/1.0',
                    CURLOPT_RANGE => '0-2048',
                ]);
                curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err = curl_error($ch) ?: $err;
                curl_close($ch);
            }
            $results[] = [
                'file' => $rel,
                'url' => $url,
                'status' => $code,
                'ok' => $code >= 200 && $code < 400,
                'error' => $err !== '' ? $err : null,
            ];
        }
        return ['available' => true, 'results' => $results];
    }

    /** @return array<int,array> */
    public static function backups(): array
    {
        $dir = Store::backups();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($dir . '/' . $entry)) {
                continue;
            }
            $meta = Store::readJson($dir . '/' . $entry . '/meta.json', []);
            $files = Store::listFiles($dir . '/' . $entry . '/working');
            $out[] = [
                'id' => $entry,
                'createdAt' => $meta['createdAt'] ?? date('c', filemtime($dir . '/' . $entry)),
                'message' => $meta['message'] ?? '',
                'type' => $meta['type'] ?? 'publish',
                'user' => $meta['user'] ?? '',
                'files' => count($files),
                'remoteFiles' => count($meta['remoteBackup'] ?? []),
                'applied' => count($meta['applied'] ?? []),
                'failed' => count($meta['failed'] ?? []),
                'liveCheck' => $meta['liveCheck'] ?? null,
            ];
        }
        usort($out, static function ($a, $b) {
            $ta = strtotime((string) $a['createdAt']) ?: 0;
            $tb = strtotime((string) $b['createdAt']) ?: 0;
            if ($ta === $tb) {
                return strcmp((string) $b['id'], (string) $a['id']);
            }
            return $tb <=> $ta;
        });
        foreach ($out as &$row) {
            $ts = strtotime((string) $row['createdAt']) ?: 0;
            $row['day'] = $ts > 0 ? date('Y-m-d', $ts) : '';
        }
        unset($row);
        return $out;
    }

    public static function backupDetail(string $id): array
    {
        $dir = Store::backups() . '/' . self::safeBackupId($id);
        if (!is_dir($dir)) {
            throw new AppError('Backup nicht gefunden.', 404);
        }
        $meta = Store::readJson($dir . '/meta.json', []);
        $files = array_map(static fn($f) => $f['path'], Store::listFiles($dir . '/working'));
        return ['id' => $id, 'meta' => $meta, 'files' => $files];
    }

    /**
     * Backup ansehen, ohne etwas wiederherzustellen.
     * @return array{id:string,meta:array,files:array,file?:string}
     */
    public static function backupView(string $id, string $file = ''): array
    {
        $safe = self::safeBackupId($id);
        $dir = Store::backups() . '/' . $safe;
        if (!is_dir($dir)) {
            throw new AppError('Backup nicht gefunden.', 404);
        }
        $meta = Store::readJson($dir . '/meta.json', []);
        $files = [];
        foreach (Store::listFiles($dir . '/working') as $f) {
            $files[$f['path']] = ['path' => $f['path'], 'size' => $f['size'], 'text' => in_array(Store::extOf($f['path']), WFX_EDIT_EXT, true)];
        }
        foreach (Store::listFiles($dir . '/remote') as $f) {
            $key = $f['path'];
            if (isset($files[$key])) {
                continue;
            }
            $files[$key] = ['path' => $key, 'size' => $f['size'], 'text' => in_array(Store::extOf($key), WFX_EDIT_EXT, true), 'remote' => true];
        }
        ksort($files);
        $out = ['id' => $safe, 'meta' => $meta, 'files' => array_values($files)];
        if ($file === '') {
            return $out;
        }
        $rel = Store::normRel($file, true);
        $base = '';
        foreach (['working', 'remote'] as $candidate) {
            if (Store::exists($rel, $dir . '/' . $candidate)) {
                $base = $candidate;
                break;
            }
        }
        if ($base === '') {
            throw new AppError('Diese Datei liegt nicht im Backup: ' . $rel, 404);
        }
        $content = Store::read($rel, $dir . '/' . $base);
        $current = Store::exists($rel, Store::working()) ? Store::read($rel, Store::working()) : null;
        $limit = WFX_MAX_EDIT_BYTES;
        $out['file'] = $rel;
        $out['source'] = $base;
        $out['size'] = strlen($content);
        $out['isBinary'] = !in_array(Store::extOf($rel), WFX_EDIT_EXT, true);
        $out['content'] = ($out['isBinary'] || strlen($content) > $limit) ? null : $content;
        $out['current'] = ($out['isBinary'] || ($current !== null && strlen($current) > $limit)) ? null : $current;
        $out['identical'] = $current !== null && $current === $content;
        $out['diff'] = $out['isBinary'] ? [] : Api::unifiedDiff($content, $current ?? '');
        return $out;
    }

    private static function safeBackupId(string $id): string
    {
        if (!preg_match('/^(manual-)?[0-9]{8}-[0-9]{6}-[a-f0-9]{5}$/', $id)) {
            throw new AppError('Ungueltige Backup-ID.', 400);
        }
        return $id;
    }

    /**
     * Stellt ein Backup wieder her.
     * @param string $target working|remote|both
     */
    public static function rollback(string $id, string $target = 'both'): array
    {
        Auth::requirePerm('restore');
        $safe = self::safeBackupId($id);
        $dir = Store::backups() . '/' . $safe;
        if (!is_dir($dir)) {
            throw new AppError('Backup nicht gefunden.', 404);
        }
        $files = array_map(static fn($f) => $f['path'], Store::listFiles($dir . '/working'));
        if (!$files) {
            throw new AppError('Dieses Backup enthaelt keine wiederherstellbaren Dateien.', 422);
        }

        // 1. Aktuellen Zustand vor dem Rollback sichern
        $safetyId = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        $safetyDir = Store::backups() . '/' . $safetyId;
        $safetyManifest = [
            'id' => $safetyId,
            'createdAt' => date('c'),
            'user' => Auth::user()['name'] ?? null,
            'message' => 'Sicherung vor Rollback auf ' . $id,
            'type' => 'pre-rollback',
            'files' => $files,
        ];
        foreach ($files as $rel) {
            if (Store::exists($rel)) {
                Store::copyFile($rel, Store::working(), $safetyDir . '/working');
            }
        }
        Store::writeJson($safetyDir . '/meta.json', $safetyManifest);

        // 2. Wiederherstellen in die Arbeitskopie
        foreach ($files as $rel) {
            $content = Store::read($rel, $dir . '/working');
            Store::writeAtomic($rel, $content, Store::working());
        }

        // 3. Pruefen
        $validation = Validator::validate($files, false);
        $result = [
            'id' => $safetyId,
            'restored' => $files,
            'validation' => $validation,
            'pushed' => [],
            'failed' => [],
            'blocked' => null,
        ];
        if ($validation['counts']['error'] > 0) {
            $result['blocked'] = 'Wiederherstellung in die Arbeitskopie erfolgt, Veroeffentlichung blockiert wegen Prueffehlern.';
            Journal::write('rollback', null, ['from' => $id, 'safety' => $safetyId, 'blocked' => true]);
            return $result;
        }

        if ($target === 'remote' || $target === 'both') {
            $sourceType = Config::source()['type'] ?? 'ftp';
            if ($sourceType === 'ftp' && !empty(Config::source()['host'])) {
                $push = Sync::pushFiles($files, []);
                $result['pushed'] = $push['uploaded'];
                $result['failed'] = $push['failed'];
                $failedFiles = array_column($push['failed'], 'file');
                foreach ($push['uploaded'] as $rel) {
                    if (!in_array($rel, $failedFiles, true)) {
                        Store::copyFile($rel, Store::working(), Store::live());
                    }
                }
                $result['liveCheck'] = self::liveCheck(array_values(array_filter($files, static fn($rel) => in_array(Store::extOf($rel), ['html', 'htm', 'php'], true))));
            } else {
                foreach ($files as $rel) {
                    Store::copyFile($rel, Store::working(), Store::live());
                }
                $result['blocked'] = 'Kein FTP konfiguriert - nur lokal wiederhergestellt.';
            }
        }
        Journal::write('rollback', null, ['from' => $id, 'safety' => $safetyId, 'target' => $target]);
        return $result;
    }
}
