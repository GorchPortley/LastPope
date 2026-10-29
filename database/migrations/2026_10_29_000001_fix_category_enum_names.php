<?php

use App\Enums\DesignCategoryEnum;
use App\Enums\DriverCategoryEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard design and driver forms used to save the enum case name (TWO_WAY)
 * instead of its value (Two-Way). Convert any rows saved that way.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DesignCategoryEnum::cases() as $case) {
            DB::table('designs')->where('category', $case->name)->update(['category' => $case->value]);
        }

        foreach (DriverCategoryEnum::cases() as $case) {
            DB::table('drivers')->where('category', $case->name)->update(['category' => $case->value]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the case names were never valid values.
    }
};
