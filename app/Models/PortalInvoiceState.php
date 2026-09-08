<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalInvoiceState extends Model
{
    protected $primaryKey = 'invoice_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['was_published' => 'boolean', 'send_at' => 'datetime'];
}
