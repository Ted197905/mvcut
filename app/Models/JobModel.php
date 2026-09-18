<?php

namespace App\Models;

use CodeIgniter\Model;

class JobModel extends Model
{
    protected $table         = 'jobs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'user_id', 'media_id', 'type', 'params', 'status', 'progress',
        'result_media_id', 'error', 'started_at', 'finished_at',
    ];
    protected $useTimestamps = true;
}
