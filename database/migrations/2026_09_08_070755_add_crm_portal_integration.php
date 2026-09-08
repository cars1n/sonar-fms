<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE students ALTER COLUMN sis_id DROP NOT NULL');
        Schema::table('students', function (Blueprint $table): void {
            $table->uuid('crm_reference')->nullable();
            $table->string('application_reference')->nullable();
            $table->uuid('merged_into_uuid')->nullable();
            $table->unique(['tenant_id', 'school_id', 'crm_reference'], 'students_crm_identity');
        });
        Schema::table('invoice_pdfs', function (Blueprint $table): void {
            $table->string('portal_revision', 64)->nullable();
        });
        Schema::create('portal_invoice_states', function (Blueprint $table): void {
            $table->uuid('invoice_uuid')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('school_id');
            $table->string('fingerprint', 64)->nullable();
            $table->uuid('request_id')->nullable();
            $table->boolean('was_published')->default(false);
            $table->timestamp('send_at')->nullable();
            $table->timestamps();
        });
        Schema::create('portal_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('school_id')->index();
            $table->json('payload');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_events');
        Schema::dropIfExists('portal_invoice_states');
        Schema::table('invoice_pdfs', fn (Blueprint $table) => $table->dropColumn('portal_revision'));
        Schema::table('students', function (Blueprint $table): void {
            $table->dropUnique('students_crm_identity');
            $table->dropColumn(['crm_reference', 'application_reference', 'merged_into_uuid']);
        });
    }
};
