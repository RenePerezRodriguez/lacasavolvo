<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    use HasFactory;
    protected $table = 'empresas';
    protected $fillable = ['nombre', 'localidad_id', 'estado'];

    public function localidad()
    {
        return $this->belongsTo(Localidad::class);
    }
}
