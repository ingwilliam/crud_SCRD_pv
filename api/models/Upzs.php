<?php
use Phalcon\Mvc\Model;

class Upzs extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Barrio
        $this->hasMany(
            'id',
            'Barrio',
            'upz'
        );
        
        //Se define relacion de N a 1 con Localidades
        $this->belongsTo(
            'localidad',
            'Localidades',
            'id',
            [
                'foreignKey' => true
            ]
        );
        
        //Se define la relación con 1 a N con propuestas
        $this->hasMany(
            'id',
            'Propuestas',
            'upz'
        ); 
    }
}