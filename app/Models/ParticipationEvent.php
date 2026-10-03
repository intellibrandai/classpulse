<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParticipationEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'operation_seq',
        'school_class_id',
        'student_id',
        'before_exists',
        'before_status',
        'before_points',
        'before_restore_points',
    ];
}
