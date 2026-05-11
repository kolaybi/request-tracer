<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use KolayBi\RequestTracer\Support\TraceTableBlueprint;

return new class () extends Migration {
    public function up(): void
    {
        $persistentTable = config('kolaybi.request-tracer.outgoing.table', 'outgoing_request_traces') . '_persistent';

        Schema::connection(config('kolaybi.request-tracer.connection'))
            ->create($persistentTable, function (Blueprint $table) {
                TraceTableBlueprint::outgoing($table);
            });
    }

    public function down(): void
    {
        $persistentTable = config('kolaybi.request-tracer.outgoing.table', 'outgoing_request_traces') . '_persistent';

        Schema::connection(config('kolaybi.request-tracer.connection'))
            ->dropIfExists($persistentTable);
    }
};
