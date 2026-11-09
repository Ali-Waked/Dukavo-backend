<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->foreignId('automation_template_id')
                ->nullable()
                ->after('store_id')
                ->constrained('automation_templates')
                ->nullOnDelete();
            $table->string('template_version', 20)->nullable()->after('automation_template_id');
            $table->json('configuration')->nullable()->after('actions');
            $table->boolean('template_customized')->default(false)->after('configuration');
            $table->timestamp('installed_at')->nullable()->after('template_customized');
            $table->timestamp('enabled_at')->nullable()->after('installed_at');

            $table->index(['tenant_id', 'automation_template_id']);
        });
    }

    public function down(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'automation_template_id']);
            $table->dropConstrainedForeignId('automation_template_id');
            $table->dropColumn([
                'template_version',
                'configuration',
                'template_customized',
                'installed_at',
                'enabled_at',
            ]);
        });
    }
};
