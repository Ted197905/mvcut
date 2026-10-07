<?php

namespace App\Models;

use CodeIgniter\Model;

class CategoryModel extends Model
{
    protected $table         = 'categories';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['name', 'sort'];
    protected $useTimestamps = true;

    /** Library filter values that are not category names. */
    public const ALL  = 'ALL';
    public const NONE = '_none';

    public function ordered(): array
    {
        return $this->orderBy('sort', 'ASC')->orderBy('id', 'ASC')->findAll();
    }

    /** @return string[] */
    public function names(): array
    {
        return array_column($this->ordered(), 'name');
    }

    /** Trimmed name, or an error message. */
    public static function clean(string $name, ?string &$error): string
    {
        $name  = trim(preg_replace('/\s+/u', ' ', $name));
        $error = null;
        if ($name === '') $error = '카테고리 이름을 입력하세요.';
        elseif (mb_strlen($name) > 32) $error = '카테고리 이름은 32자 이하로 입력하세요.';
        elseif (strcasecmp($name, self::ALL) === 0 || $name === self::NONE) $error = '"' . $name . '"은(는) 쓸 수 없는 이름입니다.';
        return $name;
    }
}
