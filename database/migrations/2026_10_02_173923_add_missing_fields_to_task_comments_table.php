<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_comments', function (Blueprint $table): void {
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('comment')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_comments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('task_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('comment');
        });
    }
};
