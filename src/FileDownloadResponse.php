<?php

namespace TearoomOne\FtpBackup;

use Kirby\Http\Response;

/**
 * Sends a backup archive in chunks instead of loading it into memory
 */
class FileDownloadResponse extends Response
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;

        parent::__construct([
            'body' => '',
            'type' => 'application/zip',
            'code' => 200,
            'headers' => [
                'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
                'Content-Length' => (string)filesize($path),
                'Last-Modified' => gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Content-Type-Options' => 'nosniff',
            ],
        ]);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Send the headers, then stream the file to the client
     */
    public function send(): string
    {
        // Discard stray output (e.g. whitespace after a closing PHP tag elsewhere)
        // that would otherwise be prepended to the archive and corrupt it
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        parent::send();

        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            return '';
        }
        while (!feof($handle)) {
            echo fread($handle, 1024 * 1024);
            flush();
        }
        fclose($handle);

        return '';
    }
}
