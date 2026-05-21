<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // JSON list of panel ids this user can access. The Workbench
            // User's canAccessPanel() reads from here so we can flip a
            // user between admin-only / employee-only / hybrid roles by
            // editing one column.
            $table->json('allowed_panels')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('allowed_panels');
        });
    }
};
