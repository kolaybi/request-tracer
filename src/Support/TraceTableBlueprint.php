<?php

namespace KolayBi\RequestTracer\Support;

use Illuminate\Database\Schema\Blueprint;

final class TraceTableBlueprint
{
    public static function incoming(Blueprint $table): void
    {
        $tenantColumn = config('kolaybi.request-tracer.tenant_column', 'tenant_id');

        $table->ulid('id')->primary();
        $table->timestamp('created_at')->nullable()->index();
        $table->timestamp('start', 3)->nullable();
        $table->timestamp('end', 3)->nullable();
        $table->unsignedBigInteger('duration')->nullable();
        $table->unsignedBigInteger($tenantColumn)->nullable()->index();
        $table->unsignedBigInteger('user_id')->nullable()->index();
        $table->ipAddress('client_ip')->nullable();
        $table->string('server_identifier', 255)->nullable();
        $table->string('trace_id')->nullable()->index();
        $table->string('channel')->nullable()->index();
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
    }

    public static function outgoing(Blueprint $table): void
    {
        $tenantColumn = config('kolaybi.request-tracer.tenant_column', 'tenant_id');

        $table->ulid('id')->primary();
        $table->timestamp('created_at')->nullable()->index();
        $table->timestamp('start', 3)->nullable();
        $table->timestamp('end', 3)->nullable();
        $table->unsignedBigInteger('duration')->nullable();
        $table->unsignedBigInteger($tenantColumn)->nullable()->index();
        $table->unsignedBigInteger('user_id')->nullable()->index();
        $table->ipAddress('client_ip')->nullable();
        $table->string('server_identifier', 255)->nullable();
        $table->string('trace_id')->nullable()->index();
        $table->string('channel')->nullable()->index();
        $table->string('protocol', 10)->nullable();
        $table->string('method', 10)->nullable();
        $table->text('host')->nullable();
        $table->text('path')->nullable();
        $table->text('query')->nullable();
        $table->longText('request_body')->nullable();
        $table->text('request_headers')->nullable();
        $table->unsignedInteger('request_size')->nullable();
        $table->unsignedSmallInteger('status')->nullable();
        $table->longText('response_body')->nullable();
        $table->text('response_headers')->nullable();
        $table->unsignedInteger('response_size')->nullable();
        $table->text('message')->nullable();
        $table->longText('exception')->nullable();
        $table->longText('stats')->nullable();
        $table->text('extra')->nullable();
    }
}
