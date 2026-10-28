<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| GA4 server-side events (docs/PLAN.md 12, R7). At checkout the buyer's GA client id (from the `_ga` cookie) and whether
| they allowed analytics are stored on the order, so the purchase sent from the webhook joins their browser session and
| is skipped when they said no. NULL = unknown (orders from before R7): treated as "no consent".
| `analytics_events` remembers which server events were sent, so a redelivered webhook never reports twice.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('ga_client_id', 64)->nullable()->after('shipping_address');
            $table->boolean('analytics_consent')->nullable()->after('ga_client_id');
        });

        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ga_client_id', 'analytics_consent']);
        });
    }
};
