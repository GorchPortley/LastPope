<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A designer's own CSS for the text blocks of the design page (summary, description, driver notes). Stored as typed;
 * App\Support\ScopedCss cleans and scopes it whenever the page is drawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            $table->text('description_css')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            $table->dropColumn('description_css');
        });
    }
};
