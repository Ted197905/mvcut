<?php

namespace App\Models;

use CodeIgniter\Model;

class KeywordModel extends Model
{
    protected $table         = 'keywords';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['user_id', 'parent_id', 'name', 'sort', 'collapsed'];
    protected $useTimestamps = true;

    /** Whole tree of one user: top-level rows with their children, in sort order. */
    public function tree(int $userId): array
    {
        $rows = $this->where('user_id', $userId)->orderBy('sort', 'ASC')->orderBy('id', 'ASC')->findAll();
        $top  = [];
        $kids = [];
        foreach ($rows as $r) {
            $node = ['id' => (int) $r['id'], 'name' => $r['name'], 'collapsed' => (bool) $r['collapsed'], 'children' => []];
            if ($r['parent_id'] === null) $top[$node['id']] = $node;
            else $kids[(int) $r['parent_id']][] = $node;
        }
        foreach ($top as $id => &$t) $t['children'] = $kids[$id] ?? [];
        return array_values($top);
    }

    public function findOwned(int $id, int $userId): ?array
    {
        return $this->where('user_id', $userId)->find($id);
    }

    public function nextSort(int $userId, ?int $parentId): int
    {
        $b = $this->where('user_id', $userId);
        $parentId === null ? $b->where('parent_id', null) : $b->where('parent_id', $parentId);
        $max = $b->selectMax('sort')->first()['sort'] ?? null;
        return $max === null ? 0 : (int) $max + 1;
    }

    /** Trimmed single-line name, or an error message. */
    public static function clean(string $name, ?string &$error): string
    {
        $name  = trim(preg_replace('/\s+/u', ' ', $name));
        $error = null;
        if ($name === '') $error = '키워드를 입력하세요.';
        elseif (mb_strlen($name) > 255) $error = '키워드는 255자 이하로 입력하세요.';
        return $name;
    }
}
