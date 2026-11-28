<?php
declare(strict_types=1);

final class PrivateMedia
{
    public static function stream(string $path, string $type, ?string $filename = null): void
    {
        $file = is_file($path) ? @fopen($path, 'rb') : false;
        if (!$file) {
            http_response_code(404);
            return;
        }

        $size = fstat($file)['size'];
        $start = 0;
        $end = $size - 1;
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        $range = $_SERVER['HTTP_RANGE'] ?? null;
        if ($range !== null) {
            if (preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $parts) && ($parts[1] !== '' || $parts[2] !== '')) {
                if ($parts[1] === '') {
                    $start = $size - min($size, (int) $parts[2]);
                } else {
                    $start = (int) $parts[1];
                    if ($parts[2] !== '') {
                        $end = min($end, (int) $parts[2]);
                    }
                }
            } else {
                $start = $size;
            }
            if ($start > $end) {
                header("Content-Range: bytes */$size");
                http_response_code(416);
                fclose($file);
                return;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }

        header('Content-Type: ' . $type);
        if ($filename !== null) {
            $ascii = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $filename);
            header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        }
        $length = $end - $start + 1;
        header('Content-Length: ' . $length);
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $length > 0) {
            stream_copy_to_stream($file, fopen('php://output', 'wb'), $length, $start);
        }
        fclose($file);
    }
}
