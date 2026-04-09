<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 50);
            $table->string('token', 255)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
        });

        DB::statement("ALTER TABLE workspace_invitations ADD CONSTRAINT workspace_invitations_role_check CHECK (role IN ('admin','member'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
