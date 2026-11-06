<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('segment', 40)->nullable()->after('document');
            $table->string('zip_code', 8)->nullable()->after('phone');
            $table->string('street')->nullable()->after('zip_code');
            $table->string('number', 20)->nullable()->after('street');
            $table->string('complement', 100)->nullable()->after('number');
            $table->string('district', 100)->nullable()->after('complement');
            $table->string('city', 100)->nullable()->after('district');
            $table->char('state', 2)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['segment', 'zip_code', 'street', 'number', 'complement', 'district', 'city', 'state']);
        });
    }
};
