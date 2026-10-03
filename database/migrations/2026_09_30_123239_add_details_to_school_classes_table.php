<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->string('title', 120)->nullable()->after('name');
            $table->string('room', 60)->nullable()->after('period_label');
            $table->string('schedule', 80)->nullable()->after('room');
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropColumn(['title', 'room', 'schedule']);
        });
    }
};
