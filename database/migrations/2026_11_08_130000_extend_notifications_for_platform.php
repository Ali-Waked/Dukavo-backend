<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra queryable dimensions on an in-app notification.
 *
 * `severity` answers "how loud is this", while `priority` answers "how soon
 * must it be handled". They are not the same question: a low-stock warning is
 * a warning in severity but can be high priority during a flash sale.
 *
 * `store_id` and the related-entity morph make the notification linkable to
 * real business records without parsing the JSON payload, while `data` keeps
 * the rendered, localized extras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->string('priority', 20)->default('normal')->after('severity');
            $table->string('related_type', 40)->nullable()->after('data');
            $table->unsignedBigInteger('related_id')->nullable()->after('related_type');

            $table->index(['notifiable_type', 'notifiable_id', 'category', 'read_at'], 'notifications_recipient_category_read_index');
            $table->index(['notifiable_type', 'notifiable_id', 'scope', 'created_at'], 'notifications_recipient_scope_created_index');
            $table->index('store_id');
            $table->index(['related_type', 'related_id'], 'notifications_related_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_recipient_category_read_index');
            $table->dropIndex('notifications_recipient_scope_created_index');
            $table->dropIndex(['related_type', 'related_id']);
            $table->dropForeign(['store_id']);
            $table->dropIndex(['store_id']);
            $table->dropColumn(['store_id', 'priority', 'related_type', 'related_id']);
        });
    }
};
