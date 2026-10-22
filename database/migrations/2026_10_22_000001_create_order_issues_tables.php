<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| "Report a problem" (docs/PLAN.md 7, R2.2). A NEW migration on purpose: the dev server holds data, so existing
| migrations are never edited and `php artisan migrate --force` applies this on a running site.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_order_id')->constrained()->cascadeOnDelete();
            // Financial records: users with issues cannot be deleted.
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            // App\Enums\IssueReason / IssueStatus / IssueResolution
            $table->string('reason');
            $table->string('status')->default('open');
            $table->string('resolution')->nullable();
            // The seller's pending proposal (IssueResolution: full_refund, partial_refund or return_refund).
            $table->string('offer_type')->nullable();
            $table->unsignedInteger('offer_cents')->nullable();
            $table->timestamp('offered_at')->nullable();
            // Money given back to the buyer through this issue, and taken back from the seller after a lost dispute.
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->unsignedInteger('recovered_cents')->default(0);
            $table->timestamp('recovered_at')->nullable();
            // Return for refund: tracking of the parcel the buyer sends back.
            $table->string('return_carrier')->nullable();
            $table->string('return_tracking_number')->nullable();
            $table->string('return_tracking_url')->nullable();
            $table->timestamp('return_shipped_at')->nullable();
            $table->timestamp('return_received_at')->nullable();
            // Stripe bank dispute (chargeback) that opened or joined this issue.
            $table->string('stripe_dispute_id')->nullable()->index();
            $table->string('dispute_status')->nullable();
            $table->string('dispute_reason')->nullable();
            $table->timestamp('dispute_due_at')->nullable();
            // Equals seller_order_id while the issue is unresolved and NULL afterwards: the unique index
            // is what guarantees "one unresolved issue per seller order", on every database.
            $table->unsignedBigInteger('open_seller_order_id')->nullable()->unique();
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['buyer_id', 'status']);
            $table->index(['seller_id', 'status']);
            $table->index('seller_order_id');
        });

        Schema::create('order_issue_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_issue_id')->constrained()->cascadeOnDelete();
            // NULL = the system (offer accepted, return tracking, dispute opened...).
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // What happened, for system lines: opened, offer, offer_accepted, offer_declined, return_tracking,
            // return_received, escalated, withdrawn, resolved, dispute, recovered. NULL for a plain message.
            $table->string('event')->nullable();
            $table->text('body');
            // [{path, name, size, mime}] on the private disk, served by an authorised route.
            $table->json('attachments')->nullable();
            // Admin-only note: never shown to the buyer or the seller.
            $table->boolean('internal')->default(false);
            $table->timestamps();

            $table->index(['order_issue_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_issue_messages');
        Schema::dropIfExists('order_issues');
    }
};
