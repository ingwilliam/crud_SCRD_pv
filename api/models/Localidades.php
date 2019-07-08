<?php
use Phalcon\Mvc\Model;

class Localidades extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Upz
        $this->hasMany(
            'id',
            'Upzs',
            'localidad'
        );
        
        //Se define la relación con 1 a N con Barrio
        $this->hasMany(
            'id',
            'Barrio',
            'localidad'
        );
        
        //Se define relacion de N a 1 con Ciudades
        $this->belongsTo(
            'ciudad',
            'Ciudades',
            'id',
            [
                'foreignKey' => true
            ]
        );
    }
}