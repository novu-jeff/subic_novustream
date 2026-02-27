<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReadingDate extends Model
{
    protected $table = 'reading_dates';

    protected $fillable = [
        'zone_id',
        'bill_period_from',
        'bill_period_to',
        'due_date',
        'is_active'
    ];

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }
}
