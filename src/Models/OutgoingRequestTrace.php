<?php

namespace KolayBi\RequestTracer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $created_at
 */
class OutgoingRequestTrace extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('kolaybi.request-tracer.connection');
    }

    public function getTable(): string
    {
        $table = config('kolaybi.request-tracer.outgoing.table', 'outgoing_request_traces');
        $schema = config('kolaybi.request-tracer.schema');

        return $schema ? "{$schema}.{$table}" : $table;
    }

    protected function casts(): array
    {
        $tenantColumn = config('kolaybi.request-tracer.tenant_column', 'tenant_id');

        return [
            'created_at'    => 'datetime',
            'duration'      => 'integer',
            'status'        => 'integer',
            'request_size'  => 'integer',
            'response_size' => 'integer',
            $tenantColumn   => config('kolaybi.request-tracer.tenant_cast', 'integer'),
            'user_id'       => config('kolaybi.request-tracer.user_cast', 'integer'),
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(fn(self $model) => $model->created_at = Carbon::now());
    }
}
