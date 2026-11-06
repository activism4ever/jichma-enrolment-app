<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ward extends Model
{
    use HasFactory;

    protected $fillable = ['lga_id', 'name'];

    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
    }

    public function enrolees(): HasMany
    {
        return $this->hasMany(Enrolee::class);
    }
}