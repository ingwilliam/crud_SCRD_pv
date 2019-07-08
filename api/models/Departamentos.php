<?php
use Phalcon\Mvc\Model;

class Departamentos extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define la relación con 1 a N con Ciudades
        $this->hasMany(
            'id',
            'Ciudades',
            'departamento'
        );
        
        //Se define relacion de N a 1 con Paises
        $this->belongsTo(
            'pais',
            'Paises',
            'id',
            [
                'foreignKey' => true
            ]
        );
        
    }    
}