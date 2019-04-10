<?php
use Phalcon\Mvc\Model;

class Ciudades extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Localidades
        $this->hasMany(
            'id',
            'Localidades',
            'ciudad'
        );
        
        //Se define relacion de N a 1 con Departamentos
        $this->belongsTo(
            'departamento',
            'Departamentos',
            'id',
            [
                'foreignKey' => true
            ]
        );
    }
}