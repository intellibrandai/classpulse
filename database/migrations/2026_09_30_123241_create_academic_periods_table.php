<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['q1', 'q2']);
            $table->string('label', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->unique(['school_class_id', 'kind'], 'academic_periods_class_kind_unique');
        });
        DB::statement('ALTER TABLE academic_periods ADD CONSTRAINT academic_periods_range_check CHECK (ends_on >= starts_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_periods');
    }
};
