<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use HasFactory;

    protected $table = 'bill';
    protected $fillable = [
        'reading_id',
        'reference_no',
        'payment_id',
        'bill_period_from',
        'bill_period_to',
        'previous_unpaid',
        'total',
        'discount',
        'penalty',
        'amount',
        'amount_after_due',
        'amount_paid',
        'change',
        'isPaid',
        'hasPenalty',
        'hasDisconnection',
        'hasDisconnected',
        'date_paid',
        'due_date',
        'payor_name',
        'bill_owner_name',
        'bill_account_no',
        'bill_address',
        'bill_meter_serial_no',
        'payment_method',
        'paid_by_reference_no',
        'cashier_id',
        'isChangeForAdvancePayment',
        'high_consumption_note',
        'partial_payment',
        'isPartial',
        'hitpay_reference',
        'hitpay_payment_id',
        'initiated_at',
    ];

    protected $casts = [
        'isPaid' => 'boolean',
        'hasPenalty' => 'boolean',
        'hasDisconnection' => 'boolean',
        'hasDisconnected' => 'boolean',
        'isChangeForAdvancePayment' => 'boolean',
        'isHighConsumption' => 'boolean',
    ];

    public function reading() {
        return $this->hasOne(Reading::class, 'id', 'reading_id');
    }

    public function breakdown() {
        return $this->hasMany(BillBreakdown::class, 'bill_id', 'id');
    }

    public function discount() {
        return $this->hasMany(BillDiscount::class, 'bill_id', 'id');
    }

    public function client()
    {
        return $this->hasOneThrough(
            User::class,
            ConcessionerAccount::class,
            'account_no',       // Foreign key on ConcessionerAccount
            'id',               // Foreign key on User
            'reading_id',       // Local key on Bill (via Reading relationship)
            'user_id'           // Local key on ConcessionerAccount
        );
    }


}
