<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class ReadinessProbe
{
    public function ready(): bool
    {
        try {
            DB::connection()->selectOne('SELECT 1');
            foreach (['local', 'support_private'] as $disk) {
                $root = config('filesystems.disks.'.$disk.'.root');
                if (! is_string($root) || ! is_dir($root) || ! is_readable($root) || ! is_writable($root)) {
                    return false;
                }
                $file = $root.'/.readiness-'.bin2hex(random_bytes(16));
                $handle = @fopen($file, 'x+b');
                if ($handle === false) {
                    return false;
                }
                try {
                    if (fwrite($handle, 'ready') !== 5 || ! rewind($handle) || fread($handle, 5) !== 'ready') {
                        return false;
                    }
                } finally {
                    fclose($handle);
                    if (! @unlink($file)) {
                        return false;
                    }
                }
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
