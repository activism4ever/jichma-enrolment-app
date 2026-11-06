<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facility extends Model
{
    use HasFactory;

    protected $fillable = ['ward_id', 'name'];

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function enrolees(): HasMany
    {
        return $this->hasMany(Enrolee::class);
    }
}