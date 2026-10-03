<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicPeriod extends Model
{
    use HasFactory;

    public const KINDS = ['q1', 'q2'];

    public const DEFAULT_LABELS = ['q1' => 'Q1 / Midterm', 'q2' => 'Q2 / Finals'];

    protected $fillable = [
        'school_class_id',
        'kind',
        'label',
        'starts_on',
        'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }
}
