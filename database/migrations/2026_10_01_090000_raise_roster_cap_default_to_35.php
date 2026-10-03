<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The per-class seat limit moves from 30 to 35 (config classpulse.max_roster is the single source).
 *
 * - The column default becomes 35 for new classes.
 * - Stored caps that equal EXACTLY 30 (the old default) are raised to 35. A class whose owner chose any other
 *   value (for example 28 or 25) keeps it; the owner can still raise it by hand up to 35.
 * - The CHECK constraint is recreated with the configured maximum. If the limit is ever changed again, add a
 *   new migration that does the same (tests/Feature/SchemaConstraintsTest fails while config and constraint disagree).
 * No student is touched: nothing is ever deleted to apply a limit.
 */
return new class extends Migration
{
    private const OLD_DEFAULT = 30;

    public function up(): void
    {
        $max = (int) config('classpulse.max_roster');

        DB::statement('ALTER TABLE school_classes DROP CONSTRAINT school_classes_roster_cap_check');
        DB::statement("ALTER TABLE school_classes ALTER COLUMN roster_cap SET DEFAULT {$max}");
        DB::table('school_classes')->where('roster_cap', self::OLD_DEFAULT)->update(['roster_cap' => $max]);
        DB::statement("ALTER TABLE school_classes ADD CONSTRAINT school_classes_roster_cap_check CHECK (roster_cap BETWEEN 1 AND {$max})");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE school_classes DROP CONSTRAINT school_classes_roster_cap_check');
        DB::statement('ALTER TABLE school_classes ALTER COLUMN roster_cap SET DEFAULT '.self::OLD_DEFAULT);
        DB::table('school_classes')->where('roster_cap', '>', self::OLD_DEFAULT)->update(['roster_cap' => self::OLD_DEFAULT]);
        DB::statement('ALTER TABLE school_classes ADD CONSTRAINT school_classes_roster_cap_check CHECK (roster_cap BETWEEN 1 AND '.self::OLD_DEFAULT.')');
    }
};
