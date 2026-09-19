<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table         = 'users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['email', 'password_hash', 'display_name', 'prefs', 'role', 'status', 'ai_enabled'];
    protected $useTimestamps = true;

    public function findByEmail(string $email): ?array
    {
        return $this->where('email', strtolower(trim($email)))->first();
    }

    public const STATUSES = ['pending', 'active', 'blocked'];

    public function isAdmin(?array $user): bool
    {
        return ($user['role'] ?? '') === 'admin';
    }

    /** Editor settings the user wants kept between videos (e.g. their watermark). */
    public function prefs(int $id): array
    {
        $row = $this->find($id);
        $p   = $row ? json_decode((string) ($row['prefs'] ?? ''), true) : null;
        return is_array($p) ? $p : [];
    }

    /** Merges one key into the stored preferences. */
    public function savePref(int $id, string $key, $value): bool
    {
        $p = $this->prefs($id);
        if ($value === null) unset($p[$key]); else $p[$key] = $value;
        return (bool) $this->update($id, ['prefs' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
    }

    public static function hash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($password, $algo);
    }
}
