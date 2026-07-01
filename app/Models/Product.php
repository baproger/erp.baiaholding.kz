<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'unit', 'price', 'description', 'is_service',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_service' => 'boolean',
    ];
}
