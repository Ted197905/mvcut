<?php

namespace App\Models;

use CodeIgniter\Model;

class MediaModel extends Model
{
    protected $table         = 'media';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'user_id', 'parent_id', 'kind', 'category', 'source', 'source_url', 'post_key', 'post_order',
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

    /**
     * Sets the category of the user's items; items imported from the same link share one card,
     * so the whole post follows. Returns the number of items changed.
     */
    public function setCategory(int $userId, array $ids, string $category): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if ($ids === []) return 0;
        $keys = array_values(array_filter(array_column(
            $this->select('post_key')->where('user_id', $userId)->whereIn('id', $ids)->findAll(), 'post_key')));
        $b = $this->db->table('media')->where('user_id', $userId)->groupStart()->whereIn('id', $ids);
        if ($keys !== []) $b->orWhereIn('post_key', $keys);
        $b->groupEnd()->update(['category' => $category]);
        return $this->db->affectedRows();
    }

    /**
     * Deletes a converted original once nothing is waiting on it: files, row, and the
     * "원본 미디어" link of its results. Returns false (keeps it) while it has queued/running jobs.
     */
    public function removeSource(array $src, int $exceptJob = 0): bool
    {
        $busy = $this->db->table('jobs')->where('media_id', $src['id'])->whereIn('status', ['queued', 'running'])
                         ->where('id !=', $exceptJob)->countAllResults();
        if ($busy > 0) return false;
        $this->db->table('media')->where('parent_id', $src['id'])->update(['parent_id' => null]);
        \App\Libraries\MediaIntake::removeDir(self::dir($src));
        $this->delete($src['id']);
        return true;
    }

    /** Storage directory for a media row (outside web root). */
    public static function dir(array $media): string
    {
        return WRITEPATH . 'media/' . $media['user_id'] . '/' . $media['id'];
    }
}
