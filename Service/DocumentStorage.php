<?php

namespace KimaiPlugin\WorkTimeLiteBundle\Service;

use App\Entity\User;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Optionale Ablage des Stundenzettels:
 *  - none:   keine Ablage (nur Download/Mail)
 *  - folder: Ordner auf dem Server/Container (z.B. eingebundene NAS-Freigabe)
 *  - webdav: beliebiger WebDAV-Server (Nextcloud, ownCloud, QNAP, Synology, ...)
 */
final class DocumentStorage
{
    public function __construct(
        private readonly Settings $settings,
        private readonly MonthDocument $document,
    )
    {
    }

    public function getType(): string
    {
        $type = $this->settings->string('storage_type', 'none');

        return \in_array($type, ['folder', 'webdav'], true) ? $type : 'none';
    }

    public function isEnabled(): bool
    {
        return $this->getType() !== 'none';
    }

    public function getTargetFolder(User $profile, \DateTimeInterface $month): string
    {
        $folder = $this->document->replace($this->settings->string('storage_folder', ''), $profile, $month);
        $parts = array_filter(array_map('trim', explode('/', str_replace('\\', '/', $folder))), fn ($p) => $p !== '' && $p !== '.' && $p !== '..');

        return implode('/', $parts);
    }

    public function describe(User $profile, \DateTimeInterface $month): string
    {
        $folder = $this->getTargetFolder($profile, $month);

        return match ($this->getType()) {
            'folder' => rtrim($this->settings->string('storage_server_path'), '/') . '/' . $folder,
            'webdav' => rtrim($this->settings->string('storage_webdav_url'), '/') . '/' . $folder,
            default => '',
        };
    }

    /**
     * @return string Ort, an dem die Datei gespeichert wurde
     */
    public function store(User $profile, \DateTimeInterface $month, string $filename, string $content, string $contentType): string
    {
        return match ($this->getType()) {
            'folder' => $this->storeFolder($profile, $month, $filename, $content),
            'webdav' => $this->storeWebdav($profile, $month, $filename, $content, $contentType),
            default => throw new \RuntimeException('Keine Ablage eingerichtet'),
        };
    }

    private function storeFolder(User $profile, \DateTimeInterface $month, string $filename, string $content): string
    {
        $base = rtrim($this->settings->string('storage_server_path'), '/');
        if ($base === '') {
            throw new \RuntimeException('Kein Server-Ordner eingetragen');
        }

        $dir = $base . '/' . $this->getTargetFolder($profile, $month);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Ordner konnte nicht angelegt werden: ' . $dir);
        }

        $path = rtrim($dir, '/') . '/' . $filename;
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Datei konnte nicht geschrieben werden: ' . $path);
        }

        return $path;
    }

    private function storeWebdav(User $profile, \DateTimeInterface $month, string $filename, string $content, string $contentType): string
    {
        $url = rtrim($this->settings->string('storage_webdav_url'), '/');
        if ($url === '') {
            throw new \RuntimeException('Keine WebDAV-Adresse eingetragen');
        }

        $options = [
            'timeout' => 30,
        ];
        $user = $this->settings->string('storage_webdav_user');
        if ($user !== '') {
            $options['auth_basic'] = [$user, $this->settings->string('storage_webdav_password')];
        }

        $client = HttpClient::create($options);

        // Ordner Schritt für Schritt anlegen (MKCOL), vorhandene Ordner sind ok
        $current = $url;
        $folder = $this->getTargetFolder($profile, $month);
        if ($folder !== '') {
            foreach (explode('/', $folder) as $part) {
                $current .= '/' . rawurlencode($part);
                $response = $client->request('MKCOL', $current . '/');
                $status = $response->getStatusCode();
                if (!\in_array($status, [201, 405, 301, 302], true) && $status >= 400) {
                    throw new \RuntimeException('Ordner konnte nicht angelegt werden (' . $status . '): ' . $current);
                }
            }
        }

        $target = $current . '/' . rawurlencode($filename);
        $response = $client->request('PUT', $target, [
            'body' => $content,
            'headers' => ['Content-Type' => $contentType],
        ]);
        $status = $response->getStatusCode();
        if ($status >= 300) {
            throw new \RuntimeException('Upload fehlgeschlagen (' . $status . '): ' . $target);
        }

        return rawurldecode($target);
    }
}
