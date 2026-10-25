<?php

namespace App\Models;

use CodeIgniter\Model;

/** Background music tracks (admin-managed, shared by every user). */
class BgmModel extends Model
{
    protected $table         = 'bgm';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['name', 'filename', 'duration', 'size', 'sort'];
    protected $useTimestamps = true;

    public const EXT = ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'opus', 'flac'];
    public const MAX_BYTES = 60 * 1024 * 1024;

    public function ordered(): array
    {
        return $this->orderBy('sort', 'ASC')->orderBy('id', 'ASC')->findAll();
    }

    public static function dir(): string
    {
        return WRITEPATH . 'bgm';
    }

    public static function path(array $row): string
    {
        return self::dir() . '/' . $row['filename'];
    }
}
