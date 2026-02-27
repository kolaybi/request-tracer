<?php

namespace KolayBi\RequestTracer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $created_at
 */
class IncomingRequestTrace extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('request-tracer.connection');
    }

    public function getTable(): string
    {
        $table = config('request-tracer.incoming.table', 'incoming_request_traces');
        $schema = config('request-tracer.schema');

        return $schema ? "{$schema}.{$table}" : $table;
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(fn(self $model) => $model->created_at = Carbon::now());
    }
}
