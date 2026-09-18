<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table         = 'users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['email', 'password_hash', 'display_name'];
    protected $useTimestamps = true;

    public function findByEmail(string $email): ?array
    {
        return $this->where('email', strtolower(trim($email)))->first();
    }

    public static function hash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($password, $algo);
    }
}
