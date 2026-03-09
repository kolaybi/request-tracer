<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $connection = config('kolaybi.request-tracer.connection');

        $outgoingTable = config('kolaybi.request-tracer.outgoing.table', 'outgoing_request_traces');

        if (Schema::connection($connection)->hasTable($outgoingTable)) {
            Schema::connection($connection)->table($outgoingTable, function (Blueprint $table) {
                $table->boolean('is_slow')->default(false)->index()->after('duration');
            });
        }

        $incomingTable = config('kolaybi.request-tracer.incoming.table', 'incoming_request_traces');

        if (Schema::connection($connection)->hasTable($incomingTable)) {
            Schema::connection($connection)->table($incomingTable, function (Blueprint $table) {
                $table->boolean('is_slow')->default(false)->index()->after('duration');
            });
        }
    }

    public function down(): void
    {
        $connection = config('kolaybi.request-tracer.connection');

        $outgoingTable = config('kolaybi.request-tracer.outgoing.table', 'outgoing_request_traces');

        if (Schema::connection($connection)->hasColumn($outgoingTable, 'is_slow')) {
            Schema::connection($connection)->table($outgoingTable, function (Blueprint $table) {
                $table->dropColumn('is_slow');
            });
        }

        $incomingTable = config('kolaybi.request-tracer.incoming.table', 'incoming_request_traces');

        if (Schema::connection($connection)->hasColumn($incomingTable, 'is_slow')) {
            Schema::connection($connection)->table($incomingTable, function (Blueprint $table) {
                $table->dropColumn('is_slow');
            });
        }
    }
};
