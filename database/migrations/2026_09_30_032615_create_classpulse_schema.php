<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('subject_description', 120)->nullable();
            $table->string('period_label', 40)->nullable();
            $table->unsignedTinyInteger('roster_cap')->default(30);
            $table->date('semester_start')->nullable();
            $table->date('semester_end')->nullable();
            $table->timestamps();
        });
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 120);
            $table->string('student_number', 40)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'school_class_id'], 'students_id_class_unique');
            $table->index(['school_class_id', 'archived_at'], 'students_class_archived_index');
        });
        Schema::create('participation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->date('work_date');
            $table->enum('status', ['present', 'absent'])->default('present');
            $table->unsignedSmallInteger('points')->nullable();
            $table->unsignedSmallInteger('restore_points')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['school_class_id', 'student_id', 'work_date'], 'participation_entries_class_student_date_unique');
            $table->index(['school_class_id', 'work_date'], 'participation_entries_class_date_index');
            $table->index(['student_id', 'school_class_id'], 'participation_entries_student_class_index');
            $table->foreign(['student_id', 'school_class_id'], 'participation_entries_student_class_foreign')
                ->references(['id', 'school_class_id'])->on('students')->cascadeOnDelete();
        });
        Schema::create('participation_operations', function (Blueprint $table) {
            $table->id('seq');
            $table->char('op_id', 36)->unique();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('kind', 20);
            $table->timestamp('undone_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['school_class_id', 'work_date', 'seq'], 'participation_operations_class_date_seq_index');
        });
        Schema::create('participation_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_seq');
            $table->unsignedBigInteger('school_class_id');
            $table->unsignedBigInteger('student_id');
            $table->boolean('before_exists');
            $table->enum('before_status', ['present', 'absent'])->nullable();
            $table->unsignedSmallInteger('before_points')->nullable();
            $table->unsignedSmallInteger('before_restore_points')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->foreign('operation_seq')->references('seq')->on('participation_operations')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE school_classes ADD CONSTRAINT school_classes_roster_cap_check CHECK (roster_cap BETWEEN 1 AND 30)');
        DB::statement('ALTER TABLE school_classes ADD CONSTRAINT school_classes_semester_check CHECK (semester_end IS NULL OR semester_start IS NULL OR semester_end >= semester_start)');
        DB::statement('ALTER TABLE participation_entries ADD CONSTRAINT participation_entries_points_check CHECK (points IS NULL OR points <= 99)');
        DB::statement('ALTER TABLE participation_entries ADD CONSTRAINT participation_entries_restore_points_check CHECK (restore_points IS NULL OR restore_points <= 99)');
        DB::statement("ALTER TABLE participation_entries ADD CONSTRAINT participation_entries_status_points_check CHECK (status = 'absent' OR points IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('participation_events');
        Schema::dropIfExists('participation_operations');
        Schema::dropIfExists('participation_entries');
        Schema::dropIfExists('students');
        Schema::dropIfExists('school_classes');
    }
};
