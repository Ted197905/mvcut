<?php

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Account changes from the shell, for bootstrapping the first admin or recovering access. */
class UserAdmin extends BaseCommand
{
    protected $group       = 'mvcut';
    protected $name        = 'user:set';
    protected $description = 'Change an account: user:set <email> admin|user|approve|block|ai-on|ai-off';
    protected $usage       = 'user:set <email> <admin|user|approve|block|ai-on|ai-off>';

    public function run(array $params)
    {
        [$email, $what] = [$params[0] ?? '', $params[1] ?? ''];
        $users = new UserModel();
        $user  = $users->findByEmail($email);
        if (! $user) { CLI::error('no such account: ' . $email); return; }

        $set = match ($what) {
            'admin'   => ['role' => 'admin'],
            'user'    => ['role' => 'user'],
            'approve' => ['status' => 'active'],
            'block'   => ['status' => 'blocked'],
            'ai-on'   => ['ai_enabled' => 1],
            'ai-off'  => ['ai_enabled' => 0],
            default   => null,
        };
        if ($set === null) { CLI::error('usage: ' . $this->usage); return; }

        $users->update($user['id'], $set);
        $now = $users->find($user['id']);
        CLI::write($now['email'] . ' -> role=' . $now['role'] . ' status=' . $now['status']
                   . ' ai=' . ($now['ai_enabled'] ? 'on' : 'off'));
    }
}
