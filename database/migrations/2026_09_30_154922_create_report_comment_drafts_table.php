<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_comment_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->string('period', 8)->default('full');
            $table->text('body');
            $table->timestamps();
            $table->unique(['student_id', 'period'], 'report_comment_drafts_student_period_unique');
            $table->index(['school_class_id', 'period'], 'report_comment_drafts_class_period_index');
            $table->foreign(['student_id', 'school_class_id'], 'report_comment_drafts_student_class_foreign')
                ->references(['id', 'school_class_id'])->on('students')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_comment_drafts');
    }
};
