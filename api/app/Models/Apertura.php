<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Apertura extends Model
{
    use HasFactory;
    protected $table = 'aperturas';
    protected $fillable = ['sucursal_id','fecha','apertura','user_id','cerrado','estado'];

    public function user() { return $this->belongsTo(\App\Models\User::class); }
    public function sucursal() { return $this->belongsTo(Sucursal::class); }
}
