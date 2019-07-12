<?php
use Phalcon\Mvc\Model;

class Tiposeventos extends Model
{
    public $id;
    
    public function initialize()
    {
        //Se define relacion de N a 1 con Paises
        $this->belongsTo(
            'programa',
            'Programas',
            'id',
            [
                'foreignKey' => true
            ]
        );
        
        //Se define la relación con 1 a N con Convocatorias
        $this->hasMany(
            'id',
            'Convocatoriascronogramas',
            'tipo_evento'
        );  
        
    }
}