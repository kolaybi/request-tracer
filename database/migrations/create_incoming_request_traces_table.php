<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection(config('request-tracer.connection'))
            ->create(config('request-tracer.incoming.table', 'incoming_request_traces'), function (Blueprint $table) {
                $tenantColumn = config('request-tracer.tenant_column', 'tenant_id');

                $table->id();
                $table->timestamp('created_at')->nullable()->index();
                $table->timestamp('start', 3)->nullable();
                $table->timestamp('end', 3)->nullable();
                $table->unsignedBigInteger('duration')->nullable()
                    ->storedAs('TIMESTAMPDIFF(MICROSECOND, start, end) / 1000')
                    ->comment('Duration in milliseconds');
                $table->unsignedBigInteger($tenantColumn)->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->ipAddress('client_ip')->nullable();
                $table->string('server_identifier', 255)->nullable();
                $table->string('trace_id')->nullable()->index();
                $table->string('method', 10)->nullable();
                $table->text('host')->nullable();
                $table->text('path')->nullable();
                $table->text('query')->nullable();
                $table->string('route')->nullable()->index();
                $table->longText('request_body')->nullable();
                $table->text('request_headers')->nullable();
                $table->unsignedInteger('request_size')->nullable();
                $table->unsignedSmallInteger('status')->nullable()->index();
                $table->longText('response_body')->nullable();
                $table->text('response_headers')->nullable();
                $table->unsignedInteger('response_size')->nullable();
            });
    }

    public function down(): void
    {
        Schema::connection(config('request-tracer.connection'))
            ->dropIfExists(config('request-tracer.incoming.table', 'incoming_request_traces'));
    }
};
