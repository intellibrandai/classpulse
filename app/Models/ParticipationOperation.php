<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParticipationOperation extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $primaryKey = 'seq';

    protected $fillable = [
        'op_id',
        'school_class_id',
        'work_date',
        'kind',
        'undone_at',
    ];

    protected function casts(): array
    {
        return [
            'undone_at' => 'datetime',
        ];
    }
}
