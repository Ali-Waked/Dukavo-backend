<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A voice note is the bridge between an inbound WhatsApp attachment and a
     * reviewed cart draft. The original media path is kept only long enough to
     * transcribe it; the transcript and the reviewed draft are the durable
     * records, and the media can be purged by the retention command.
     */
    public function up(): void
    {
        Schema::create('voice_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('queued');
            $table->string('media_path')->nullable();
            $table->string('media_mime_type', 100)->nullable();
            $table->unsignedBigInteger('media_size')->nullable();
            $table->string('media_filename')->nullable();
            $table->timestamp('media_purged_at')->nullable();
            $table->longText('transcript')->nullable();
            $table->string('language', 20)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('transcription_latency_ms')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('extraction')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('applied_cart_id')->nullable()->constrained('carts')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'voice_messages_conversation_index');
            $table->index(['tenant_id', 'status', 'created_at'], 'voice_messages_status_index');
            $table->index(['tenant_id', 'media_purged_at', 'created_at'], 'voice_messages_retention_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_messages');
    }
};
