<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->date('note_date');
            $table->text('body');
            $table->timestamps();
            $table->unique(['student_id', 'note_date'], 'student_notes_student_date_unique');
            $table->index(['school_class_id', 'note_date'], 'student_notes_class_date_index');
            $table->foreign(['student_id', 'school_class_id'], 'student_notes_student_class_foreign')
                ->references(['id', 'school_class_id'])->on('students')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_notes');
    }
};
