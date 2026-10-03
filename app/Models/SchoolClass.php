<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    use HasFactory;

    protected $table = 'school_classes';

    protected $fillable = [
        'name',
        'title',
        'subject_description',
        'period_label',
        'room',
        'schedule',
        'roster_cap',
        'semester_start',
        'semester_end',
    ];

    protected function casts(): array
    {
        return [
            'semester_start' => 'date:Y-m-d',
            'semester_end' => 'date:Y-m-d',
        ];
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(StudentNote::class);
    }

    public function academicPeriods(): HasMany
    {
        return $this->hasMany(AcademicPeriod::class);
    }

    public function activeStudents(): HasMany
    {
        return $this->hasMany(Student::class)->whereNull('archived_at');
    }

    public function activeStudentCount(): int
    {
        return $this->activeStudents()->count();
    }

    /**
     * The seats this class really has: the stored cap, never above the central limit (config classpulse.max_roster).
     */
    public function seatLimit(): int
    {
        return min((int) $this->roster_cap, (int) config('classpulse.max_roster'));
    }

    /**
     * Free seats; 0 (never negative) when a class already holds more active students than its limit.
     * Archived students do not occupy a seat.
     */
    public function remainingCapacity(?int $active = null): int
    {
        return max(0, $this->seatLimit() - ($active ?? $this->activeStudentCount()));
    }

    /**
     * The single wording for "no seat left", used by every add, import and restore path.
     */
    public function fullMessage(?int $active = null): string
    {
        $active ??= $this->activeStudentCount();

        return "This class is full: {$active} of {$this->seatLimit()} active students. Archive a student to free a seat.";
    }
}
