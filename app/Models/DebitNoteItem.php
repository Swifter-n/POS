<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebitNoteItem extends Model
{
    use HasFactory;
    protected $guarded = [];

    /**
     * Item ini adalah bagian dari satu Debit Note.
     */
    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(DebitNote::class);
    }

    /**
     * Relasi ke data master produk.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
