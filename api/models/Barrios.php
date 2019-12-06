<?php
use Phalcon\Mvc\Model;

class Barrios extends Model
{
    public $id;
    
    public function initialize()
    {
        
        //Se define relacion de N a 1 con Localidades
        $this->belongsTo(
            'localidad',
            'Localidades',
            'id',
            [
                'foreignKey' => true
            ]
        );
        
        //Se define relacion de N a 1 con Upz
        $this->belongsTo(
            'upz',
            'Upzs',
            'id',
            [
                'foreignKey' => true
            ]
        );
        
        //Se define la relación con 1 a N con Participantes
        $this->hasMany(
            'id',
            'Participantes',
            'barrio_residencia'
        );
        
        //Se define la relación con 1 a N con propuestas
        $this->hasMany(
            'id',
            'Propuestas',
            'barrio'
        );  
        
        
    }
    
}