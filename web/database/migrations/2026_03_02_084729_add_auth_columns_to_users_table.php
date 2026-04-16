<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('name')->after('id');
        $table->string('email')->unique()->after('name');
        $table->timestamp('email_verified_at')->nullable()->after('email');
        $table->string('password')->after('email_verified_at');
        $table->rememberToken();
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropRememberToken();
        $table->dropColumn(['password', 'email_verified_at', 'email', 'name']);
    });
}
};
