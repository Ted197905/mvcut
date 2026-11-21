<?php

namespace App\Models;

use CodeIgniter\Model;

class MediaModel extends Model
{
    protected $table         = 'media';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'user_id', 'parent_id', 'kind', 'category', 'source', 'origin', 'source_url', 'post_key', 'post_order',
        'description', 'x_text', 'uploader', 'stats', 'meta', 'title', 'filename',
        'media_type', 'mime', 'container', 'vcodec', 'acodec', 'width', 'height',
        'duration', 'fps', 'size', 'has_thumb', 'has_proxy', 'edit_params', 'status',
    ];
    protected $useTimestamps = true;
    protected $beforeDelete  = ['rememberLink'];

    /** The link of a deleted item is kept (media_links), so importing or auto-collecting it again is noticed. */
    protected function rememberLink(array $data): array
    {
        $ids = array_filter((array) ($data['id'] ?? []));
        if ($ids === []) return $data;
        try {
            foreach ($this->db->table('media')->select('user_id, source_url, origin')->whereIn('id', $ids)
                         ->where('source_url IS NOT NULL', null, false)->where('source_url !=', '')->get()->getResultArray() as $r) {
                $this->db->query('INSERT INTO media_links (user_id, url_key, source_url, origin, deleted_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP()) '
                                 . 'ON DUPLICATE KEY UPDATE deleted_at = VALUES(deleted_at)',
                                 [(int) $r['user_id'], mb_substr(\App\Libraries\MediaSupport::urlKey((string) $r['source_url']), 0, 190), $r['source_url'], $r['origin'] ?: 'manual']);
            }
        } catch (\Throwable $e) {
            log_message('error', 'media_links: ' . $e->getMessage());
        }
        return $data;
    }

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
