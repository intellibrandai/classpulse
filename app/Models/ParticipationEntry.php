<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParticipationEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_class_id',
        'student_id',
        'work_date',
        'status',
        'points',
        'restore_points',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
        ];
    }
}
