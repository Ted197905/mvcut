<?php

namespace App\Models;

use CodeIgniter\Model;

class MediaModel extends Model
{
    protected $table         = 'media';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'user_id', 'parent_id', 'kind', 'source', 'source_url', 'post_key', 'post_order',
        'description', 'uploader', 'stats', 'meta', 'title', 'filename',
        'media_type', 'mime', 'container', 'vcodec', 'acodec', 'width', 'height',
        'duration', 'fps', 'size', 'has_thumb', 'has_proxy', 'edit_params', 'status',
    ];
    protected $useTimestamps = true;

    public function forUser(int $userId): array
    {
        return $this->where('user_id', $userId)->orderBy('id', 'DESC')->findAll();
    }

    public function findOwned(int $id, int $userId): ?array
    {
        return $this->where('id', $id)->where('user_id', $userId)->first();
    }

    /** Every item imported from the same link, in post order. */
    public function postItems(array $item): array
    {
        if (empty($item['post_key'])) return [$item];
        return $this->where('user_id', $item['user_id'])->where('post_key', $item['post_key'])
                    ->orderBy('post_order', 'ASC')->orderBy('id', 'ASC')->findAll();
    }

    /** Storage directory for a media row (outside web root). */
    public static function dir(array $media): string
    {
        return WRITEPATH . 'media/' . $media['user_id'] . '/' . $media['id'];
    }
}
