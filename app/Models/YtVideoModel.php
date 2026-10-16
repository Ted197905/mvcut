<?php

namespace App\Models;

use CodeIgniter\Model;

class YtVideoModel extends Model
{
    protected $table         = 'yt_videos';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'video_id', 'url', 'title', 'channel', 'description', 'uploaded_at', 'duration', 'width', 'height', 'size',
        'filename', 'has_thumb', 'subs', 'cast', 'summary', 'status', 'error',
    ];
    protected $useTimestamps = true;

    public static function dir(array $row): string
    {
        return WRITEPATH . 'media/yt/' . $row['id'];
    }

    /** Subtitle rows [{lang, name, auto, file}] in the order they were saved. */
    public static function subs(array $row): array
    {
        return json_decode((string) ($row['subs'] ?? ''), true) ?: [];
    }

    public static function cast(array $row): array
    {
        return json_decode((string) ($row['cast'] ?? ''), true) ?: [];
    }
}
