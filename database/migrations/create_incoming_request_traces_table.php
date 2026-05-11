<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use KolayBi\RequestTracer\Support\TraceTableBlueprint;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection(config('kolaybi.request-tracer.connection'))
            ->create(config('kolaybi.request-tracer.incoming.table', 'incoming_request_traces'), function (Blueprint $table) {
                TraceTableBlueprint::incoming($table);
            });
    }

    public function down(): void
    {
        Schema::connection(config('kolaybi.request-tracer.connection'))
            ->dropIfExists(config('kolaybi.request-tracer.incoming.table', 'incoming_request_traces'));
    }
};
