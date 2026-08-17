<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymongoWebhookEventLog extends Model
{
    protected $table = 'paymongo_webhook_event_logs';

    protected $guarded = ['id'];
}
