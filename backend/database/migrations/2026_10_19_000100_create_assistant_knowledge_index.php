<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_knowledge_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->string('source_key', 120);
            $table->string('source_type', 32);
            $table->string('title');
            $table->string('version', 80);
            $table->string('locale', 8)->default('en');
            $table->string('source_uri', 1000)->nullable();
            $table->string('status', 24)->default('approved');
            $table->char('content_hash', 64);
            $table->dateTime('approved_at');
            $table->dateTime('indexed_at');
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'locale', 'store_id'], 'assistant_knowledge_docs_scope_idx');
            $table->index(['source_key', 'store_id', 'status'], 'assistant_knowledge_docs_source_idx');
        });

        Schema::create('assistant_knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('assistant_knowledge_documents')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->text('content');
            $table->text('search_text');
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->unique(['document_id', 'sequence'], 'assistant_knowledge_chunks_document_sequence_unique');
            $table->index('document_id', 'assistant_knowledge_chunks_document_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_knowledge_chunks');
        Schema::dropIfExists('assistant_knowledge_documents');
    }
};
